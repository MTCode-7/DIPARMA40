<?php
/**
 * Local TRON helpers: Base58Check addresses and secp256k1 signatures.
 * The private key stays on this host. It is never sent to TronGrid.
 */
require_once __DIR__ . '/../includes/base58.php';

final class TronSigner
{
    private const P = '115792089237316195423570985008687907853269984665640564039457584007908834671663';
    private const N = '115792089237316195423570985008687907852837564279074904382605163141518161494337';
    private const GX = '55066263022277343669578718895168534326250603453777594175500187360389116729240';
    private const GY = '32670510020758816978083085130507043184471273380659243275938904335757337482424';

    public static function addressHex(string $base58): string
    {
        return dp_tron_address_hex($base58);
    }

    public static function abiWord(string $base58): string
    {
        return dp_tron_address_abi($base58);
    }

    /** 65-byte recoverable signature as hex: r || s || v. */
    public static function signTxId(string $txIdHex, string $privateKeyHex): string
    {
        if (!extension_loaded('bcmath')) {
            throw new RuntimeException('TRON signing needs the bcmath extension');
        }
        $hash = self::normalizeHex($txIdHex, 'txID');
        $priv = self::normalizeHex($privateKeyHex, 'private key');
        $d = self::hexToDec($priv);
        if (bccomp($d, '0') <= 0 || bccomp($d, self::N) >= 0) {
            throw new InvalidArgumentException('Invalid TRON private key');
        }

        $k = self::rfc6979($hash, $priv);
        $point = self::mul($k, [self::GX, self::GY]);
        $r = self::mod($point[0], self::N);
        if (bccomp($r, '0') === 0) {
            throw new RuntimeException('TRON signature r was zero');
        }
        $z = self::hexToDec($hash);
        $s = self::mod(bcmul(self::inv($k, self::N), self::mod(bcadd($z, bcmul($r, $d)), self::N)), self::N);
        if (bccomp($s, '0') === 0) {
            throw new RuntimeException('TRON signature s was zero');
        }

        $rec = (bccomp(bcmod($point[1], '2'), '0') === 0) ? 0 : 1;
        if (bccomp($point[0], self::N) >= 0) {
            $rec |= 2;
        }
        $half = bcdiv(self::N, '2', 0);
        if (bccomp($s, $half) === 1) {
            $s = bcsub(self::N, $s);
            $rec ^= 1;
        }

        return self::decToFixedHex($r, 32) . self::decToFixedHex($s, 32) . str_pad(dechex($rec), 2, '0', STR_PAD_LEFT);
    }

    /** @return array{0:string,1:string} */
    public static function publicKey(string $privateKeyHex): array
    {
        $d = self::hexToDec(self::normalizeHex($privateKeyHex, 'private key'));
        return self::mul($d, [self::GX, self::GY]);
    }

    /** @return array{0:string,1:string} */
    public static function recoverPublicKey(string $txIdHex, string $signatureHex): array
    {
        $hash = self::normalizeHex($txIdHex, 'txID');
        $sig = strtolower(ltrim($signatureHex, '0x'));
        if (!preg_match('/^[0-9a-f]{130}$/', $sig)) {
            throw new InvalidArgumentException('TRON signature must be 65 bytes');
        }
        $r = self::hexToDec(substr($sig, 0, 64));
        $s = self::hexToDec(substr($sig, 64, 64));
        $rec = hexdec(substr($sig, 128, 2));
        $x = $r;
        if (($rec & 2) !== 0) {
            $x = bcadd($x, self::N);
        }
        $y = self::yFromX($x, ($rec & 1) === 1);
        $z = self::hexToDec($hash);
        $rInv = self::inv($r, self::N);
        $u1 = self::mod(bcmul(self::mod(bcsub('0', $z), self::N), $rInv), self::N);
        $u2 = self::mod(bcmul($s, $rInv), self::N);
        $left = self::mul($u1, [self::GX, self::GY]);
        $right = self::mul($u2, [$x, $y]);
        return self::jaffine(self::jadd([$left[0], $left[1], '1'], [$right[0], $right[1], '1']));
    }

    private static function normalizeHex(string $hex, string $label): string
    {
        $hex = strtolower(trim($hex));
        if (str_starts_with($hex, '0x')) {
            $hex = substr($hex, 2);
        }
        if (!preg_match('/^[0-9a-f]{64}$/', $hex)) {
            throw new InvalidArgumentException("TRON {$label} must be 32 bytes");
        }
        return $hex;
    }

    private static function yFromX(string $x, bool $odd): string
    {
        $y2 = self::mod(bcadd(bcpow($x, '3'), '7'), self::P);
        $y = self::modPow($y2, bcdiv(bcadd(self::P, '1'), '4', 0), self::P);
        $isOdd = bccomp(bcmod($y, '2'), '1') === 0;
        if ($isOdd !== $odd) {
            $y = bcsub(self::P, $y);
        }
        return $y;
    }

    /** @param array{0:string,1:string} $point @return array{0:string,1:string} */
    private static function mul(string $k, array $point): array
    {
        $result = null;
        $addend = [$point[0], $point[1], '1'];
        while (bccomp($k, '0') === 1) {
            if (bccomp(bcmod($k, '2'), '1') === 0) {
                $result = $result === null ? $addend : self::jadd($result, $addend);
            }
            $addend = self::jdbl($addend);
            $k = bcdiv($k, '2', 0);
        }
        if ($result === null) {
            throw new RuntimeException('TRON point multiplication failed');
        }
        return self::jaffine($result);
    }

    /** @param array{0:string,1:string,2:string} $a @param array{0:string,1:string,2:string} $b @return array{0:string,1:string,2:string} */
    private static function jadd(array $a, array $b): array
    {
        $z1s = self::mod(bcmul($a[2], $a[2]), self::P);
        $z2s = self::mod(bcmul($b[2], $b[2]), self::P);
        $u1 = self::mod(bcmul($a[0], $z2s), self::P);
        $u2 = self::mod(bcmul($b[0], $z1s), self::P);
        $s1 = self::mod(bcmul($a[1], bcmul($b[2], $z2s)), self::P);
        $s2 = self::mod(bcmul($b[1], bcmul($a[2], $z1s)), self::P);
        if (bccomp($u1, $u2) === 0) {
            if (bccomp($s1, $s2) !== 0) {
                return ['0', '1', '0'];
            }
            return self::jdbl($a);
        }
        $h = self::mod(bcsub($u2, $u1), self::P);
        $r = self::mod(bcsub($s2, $s1), self::P);
        $h2 = self::mod(bcmul($h, $h), self::P);
        $h3 = self::mod(bcmul($h2, $h), self::P);
        $u1h2 = self::mod(bcmul($u1, $h2), self::P);
        $x = self::mod(bcsub(bcsub(self::mod(bcmul($r, $r), self::P), $h3), bcmul('2', $u1h2)), self::P);
        $y = self::mod(bcsub(bcmul($r, bcsub($u1h2, $x)), bcmul($s1, $h3)), self::P);
        $z = self::mod(bcmul(bcmul($h, $a[2]), $b[2]), self::P);
        return [$x, $y, $z];
    }

    /** @param array{0:string,1:string,2:string} $a @return array{0:string,1:string,2:string} */
    private static function jdbl(array $a): array
    {
        $a2 = self::mod(bcmul($a[0], $a[0]), self::P);
        $b2 = self::mod(bcmul($a[1], $a[1]), self::P);
        $c = self::mod(bcmul($b2, $b2), self::P);
        $xb = self::mod(bcadd($a[0], $b2), self::P);
        $d = self::mod(bcmul('2', bcsub(bcsub(self::mod(bcmul($xb, $xb), self::P), $a2), $c)), self::P);
        $e = self::mod(bcmul('3', $a2), self::P);
        $f = self::mod(bcmul($e, $e), self::P);
        $x = self::mod(bcsub($f, bcmul('2', $d)), self::P);
        $y = self::mod(bcsub(bcmul($e, bcsub($d, $x)), bcmul('8', $c)), self::P);
        $z = self::mod(bcmul(bcmul('2', $a[1]), $a[2]), self::P);
        return [$x, $y, $z];
    }

    /** @param array{0:string,1:string,2:string} $p @return array{0:string,1:string} */
    private static function jaffine(array $p): array
    {
        if (bccomp($p[2], '0') === 0) {
            throw new RuntimeException('TRON point is at infinity');
        }
        $zInv = self::inv($p[2], self::P);
        $zInv2 = self::mod(bcmul($zInv, $zInv), self::P);
        $zInv3 = self::mod(bcmul($zInv2, $zInv), self::P);
        return [
            self::mod(bcmul($p[0], $zInv2), self::P),
            self::mod(bcmul($p[1], $zInv3), self::P),
        ];
    }

    private static function inv(string $a, string $mod): string
    {
        return self::modPow(self::mod($a, $mod), bcsub($mod, '2'), $mod);
    }

    private static function modPow(string $base, string $exp, string $mod): string
    {
        $result = '1';
        $base = self::mod($base, $mod);
        while (bccomp($exp, '0') === 1) {
            if (bccomp(bcmod($exp, '2'), '1') === 0) {
                $result = self::mod(bcmul($result, $base), $mod);
            }
            $base = self::mod(bcmul($base, $base), $mod);
            $exp = bcdiv($exp, '2', 0);
        }
        return $result;
    }

    private static function mod(string $a, string $n): string
    {
        $r = bcmod($a, $n);
        return bccomp($r, '0') < 0 ? bcadd($r, $n) : $r;
    }

    private static function rfc6979(string $hashHex, string $privHex): string
    {
        $x = hex2bin($privHex);
        $h = hex2bin($hashHex);
        $v = str_repeat("\x01", 32);
        $k = str_repeat("\x00", 32);
        $k = hash_hmac('sha256', $v . "\x00" . $x . $h, $k, true);
        $v = hash_hmac('sha256', $v, $k, true);
        $k = hash_hmac('sha256', $v . "\x01" . $x . $h, $k, true);
        $v = hash_hmac('sha256', $v, $k, true);
        while (true) {
            $v = hash_hmac('sha256', $v, $k, true);
            $candidate = self::hexToDec(bin2hex($v));
            if (bccomp($candidate, '0') === 1 && bccomp($candidate, self::N) === -1) {
                return $candidate;
            }
            $k = hash_hmac('sha256', $v . "\x00", $k, true);
            $v = hash_hmac('sha256', $v, $k, true);
        }
    }

    private static function hexToDec(string $hex): string
    {
        $dec = '0';
        $hex = ltrim(strtolower($hex), '0');
        if ($hex === '') {
            return '0';
        }
        foreach (str_split($hex) as $char) {
            $dec = bcadd(bcmul($dec, '16'), (string) hexdec($char));
        }
        return $dec;
    }

    private static function decToFixedHex(string $dec, int $bytes): string
    {
        $hex = '';
        if (bccomp($dec, '0') < 0) {
            throw new InvalidArgumentException('Negative integer');
        }
        while (bccomp($dec, '0') === 1) {
            $hex = dechex((int) bcmod($dec, '16')) . $hex;
            $dec = bcdiv($dec, '16', 0);
        }
        return str_pad($hex === '' ? '0' : $hex, $bytes * 2, '0', STR_PAD_LEFT);
    }

    /** Decrypt a stored hot-wallet key. Accepts raw hex, AES-256-CBC, or AES-256-GCM. */
    public static function openPrivateKey(string $stored, string $encryptionKey): string
    {
        $stored = trim($stored);
        if ($stored === '') {
            return '';
        }
        if (preg_match('/^(?:0x)?[0-9a-fA-F]{64}$/', $stored)) {
            return strtolower(ltrim($stored, '0x'));
        }
        $decoded = base64_decode($stored, true);
        if (!is_string($decoded) || $decoded === '') {
            return '';
        }
        if (str_contains($decoded, '::')) {
            [$iv, $encrypted] = explode('::', $decoded, 2);
            $plain = openssl_decrypt($encrypted, 'AES-256-CBC', $encryptionKey, 0, $iv);
            $plain = is_string($plain) ? strtolower(trim($plain)) : '';
            if (preg_match('/^[0-9a-f]{64}$/', $plain)) {
                return $plain;
            }
        }
        if (strlen($decoded) >= 28) {
            $iv = substr($decoded, 0, 12);
            $tag = substr($decoded, 12, 16);
            $cipher = substr($decoded, 28);
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', hash('sha256', $encryptionKey, true), OPENSSL_RAW_DATA, $iv, $tag);
            $plain = is_string($plain) ? strtolower(trim($plain)) : '';
            if (preg_match('/^[0-9a-f]{64}$/', $plain)) {
                return $plain;
            }
        }
        return '';
    }

    public static function addressFromPrivateKey(string $privateKeyHex): string
    {
        $point = self::publicKey($privateKeyHex);
        $pub = hex2bin(self::decToFixedHex($point[0], 32) . self::decToFixedHex($point[1], 32));
        $hash = self::keccak256($pub);
        $body = "\x41" . substr($hash, -20);
        $check = substr(hash('sha256', hash('sha256', $body, true), true), 0, 4);
        return dp_base58_encode($body . $check);
    }

    public static function keccak256(string $data): string
    {
        $rate = 136;
        $state = array_fill(0, 25, array_fill(0, 8, 0));
        $mod = strlen($data) % $rate;
        $pad = $rate - $mod;
        if ($pad === 1) {
            $padded = $data . "\x81";
        } else {
            $padded = $data . "\x01" . str_repeat("\0", $pad - 2) . "\x80";
        }
        $blocks = str_split($padded, $rate);
        $last = count($blocks) - 1;
        foreach ($blocks as $index => $block) {
            self::absorb($state, $block);
            self::keccakF($state);
            unset($last, $index);
        }
        $out = '';
        for ($i = 0; $i < 4; $i++) {
            for ($b = 0; $b < 8; $b++) {
                $out .= chr($state[$i][$b]);
            }
        }
        return $out;
    }

    /** @param array<int,array<int,int>> $state */
    private static function absorb(array &$state, string $block): void
    {
        $n = strlen($block);
        for ($i = 0; $i < $n; $i++) {
            $lane = intdiv($i, 8);
            $byte = $i % 8;
            $state[$lane][$byte] ^= ord($block[$i]);
        }
    }

    /** @param array<int,array<int,int>> $state */
    private static function keccakF(array &$state): void
    {
        $rot = [
            [0, 36, 3, 41, 18],
            [1, 44, 10, 45, 2],
            [62, 6, 43, 15, 61],
            [28, 55, 25, 21, 56],
            [27, 20, 39, 8, 14],
        ];
        $rc = [
            '0000000000000001', '0000000000008082', '800000000000808a', '8000000080008000',
            '000000000000808b', '0000000080000001', '8000000080008081', '8000000000008009',
            '000000000000008a', '0000000000000088', '0000000080008009', '000000008000000a',
            '000000008000808b', '800000000000008b', '8000000000008089', '8000000000008003',
            '8000000000008002', '8000000000000080', '000000000000800a', '800000008000000a',
            '8000000080008081', '8000000000008080', '0000000080000001', '8000000080008008',
        ];
        for ($round = 0; $round < 24; $round++) {
            $c = [];
            for ($x = 0; $x < 5; $x++) {
                $c[$x] = $state[$x];
                for ($y = 1; $y < 5; $y++) {
                    $c[$x] = self::xorLane($c[$x], $state[$x + 5 * $y]);
                }
            }
            $d = [];
            for ($x = 0; $x < 5; $x++) {
                $d[$x] = self::xorLane($c[($x + 4) % 5], self::rotLane($c[($x + 1) % 5], 1));
            }
            for ($x = 0; $x < 5; $x++) {
                for ($y = 0; $y < 5; $y++) {
                    $state[$x + 5 * $y] = self::xorLane($state[$x + 5 * $y], $d[$x]);
                }
            }
            $b = array_fill(0, 25, array_fill(0, 8, 0));
            for ($x = 0; $x < 5; $x++) {
                for ($y = 0; $y < 5; $y++) {
                    $nx = $y;
                    $ny = (2 * $x + 3 * $y) % 5;
                    $b[$nx + 5 * $ny] = self::rotLane($state[$x + 5 * $y], $rot[$x][$y]);
                }
            }
            for ($x = 0; $x < 5; $x++) {
                for ($y = 0; $y < 5; $y++) {
                    $i = $x + 5 * $y;
                    $notNext = [];
                    $next = $b[(($x + 1) % 5) + 5 * $y];
                    $next2 = $b[(($x + 2) % 5) + 5 * $y];
                    for ($k = 0; $k < 8; $k++) {
                        $notNext[$k] = (~$next[$k]) & 0xFF;
                    }
                    $and = [];
                    for ($k = 0; $k < 8; $k++) {
                        $and[$k] = $notNext[$k] & $next2[$k];
                    }
                    $state[$i] = self::xorLane($b[$i], $and);
                }
            }
            $state[0] = self::xorLane($state[0], self::rcLane($rc[$round]));
        }
    }

    /** @param array<int,int> $a @param array<int,int> $b @return array<int,int> */
    private static function xorLane(array $a, array $b): array
    {
        $out = [];
        for ($i = 0; $i < 8; $i++) {
            $out[$i] = ($a[$i] ^ $b[$i]) & 0xFF;
        }
        return $out;
    }

    /** @param array<int,int> $lane @return array<int,int> */
    private static function rotLane(array $lane, int $shift): array
    {
        $shift %= 64;
        if ($shift === 0) {
            return $lane;
        }
        $bits = [];
        for ($byte = 0; $byte < 8; $byte++) {
            for ($bit = 0; $bit < 8; $bit++) {
                $bits[] = ($lane[$byte] >> $bit) & 1;
            }
        }
        $rotated = array_fill(0, 64, 0);
        for ($i = 0; $i < 64; $i++) {
            $rotated[($i + $shift) % 64] = $bits[$i];
        }
        $out = array_fill(0, 8, 0);
        for ($i = 0; $i < 64; $i++) {
            if ($rotated[$i]) {
                $out[intdiv($i, 8)] |= 1 << ($i % 8);
            }
        }
        return $out;
    }

    /** @return array<int,int> */
    private static function rcLane(string $hex): array
    {
        $hex = str_pad(strtolower($hex), 16, '0', STR_PAD_LEFT);
        $out = [];
        for ($i = 0; $i < 8; $i++) {
            $out[$i] = hexdec(substr($hex, 14 - (2 * $i), 2));
        }
        return $out;
    }
}
