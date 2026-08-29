<?php
$key  = 'e5918b5cca26caac4ab235350fba4537';
$base = 'http://65.2.184.57:8080';

function pp(string $url, string $key, array $body): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body),CURLOPT_HTTPHEADER=>['Content-Type: application/json','API-Key: '.$key],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>false]);
    $r = curl_exec($ch); $c = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code'=>$c,'body'=>json_decode($r,true)??[],'raw'=>$r];
}

$p = pp($base.'/api/v1/payment', $key, ['customerEmail'=>'test@diparmas.com','customerId'=>'check_'.time(),'amountInUSD'=>10.0]);
$ref = $p['body']['reference_id'] ?? $p['body']['referenceID'] ?? $p['body']['referenceId'] ?? null;
echo "HTTP: {$p['code']}\n";
echo $ref ? "✅ SUCCESS — ref=$ref\n" : "❌ FAILED: {$p['raw']}\n";
