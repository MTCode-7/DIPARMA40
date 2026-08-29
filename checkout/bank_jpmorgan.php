<?php
$BANK_CONFIG = [
    'name'             => 'JP Morgan Chase — IOLTA',
    'gateway_code'     => 'jpmorgan',
    'prefix'           => 'JPM',
    'icon'             => 'fas fa-landmark',
    'color'            => '#003087',
    'default_currency' => 'USD',
    'currencies'       => ['USD'],
    'fields'           => [
        'Beneficiary'  => 'ROBERT VALLES JR IOLTA',
        'Account No'   => '663525063665',
        'Routing'      => '111000614',
        'SWIFT'        => 'CHASUS33',
        'Bank'         => 'JP Morgan Chase Bank N.A.',
        'Type'         => 'IOLTA (Trust Account)',
        'Bank Officer' => 'ANTHONY HALL',
        'Address'      => '16738 W State Highway 71, Lakeway TX.',
    ],
];
require_once __DIR__ . '/_bank_template.php';
