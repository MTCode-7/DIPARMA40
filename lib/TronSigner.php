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
}
