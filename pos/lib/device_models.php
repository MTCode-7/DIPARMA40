<?php
/**
 * Regional POS hardware — Americas, Europe, Gulf, East Asia, India, Africa,
 * plus Visa / Mastercard / American Express terminals and SoftPOS.
 */
if (defined('DI_PARMA_POS_DEVICE_MODELS')) {
    return;
}
define('DI_PARMA_POS_DEVICE_MODELS', true);

function pos_device_regions(): array
{
    return [
        'schemes' => ['ar' => 'Visa / Mastercard / Amex', 'en' => 'Visa / Mastercard / Amex'],
        'americas' => ['ar' => 'أمريكا', 'en' => 'Americas'],
        'europe' => ['ar' => 'أوروبا', 'en' => 'Europe'],
        'gulf' => ['ar' => 'الخليج', 'en' => 'Gulf'],
        'east_asia' => ['ar' => 'شرق آسيا', 'en' => 'East Asia'],
        'india' => ['ar' => 'الهند', 'en' => 'India'],
        'africa' => ['ar' => 'أفريقيا', 'en' => 'Africa'],
        'global' => ['ar' => 'عام', 'en' => 'Global'],
    ];
}

function pos_m(string $model, string $brand, string $name, string $type, string $region, array $extra = []): array
{
    $extra['region'] = $region;
    $extra['detect'] = $extra['detect'] ?? [strtolower($name)];
    return pos_device_entry($model, $brand, $name, $type, $extra);
}

function pos_device_regional_models(): array
{
    $v = 'verix_v';
    $a = 'android_smart_pos';
    $i = 'ingenico';
    $s = 'softpos';
    $t = 'tablet_reader';
    $legacy = ['pwa' => false, 'wedge' => false];

    return [
        // ── Card schemes ──────────────────────────────────────────
        pos_m('amex_go', 'American Express', 'AMEX Go', $a, 'schemes'),
        pos_m('amex_harmony', 'American Express', 'Harmony POS', $a, 'schemes'),
        pos_m('amex_mini', 'American Express', 'Mini Terminal', $i, 'schemes', $legacy),
        pos_m('amex_optblue', 'American Express', 'OptBlue POS', $a, 'schemes'),
        pos_m('amex_tap_to_pay', 'American Express', 'Tap to Pay', $s, 'schemes', ['nfc_hw' => true, 'chip' => false]),
        pos_m('visa_ready_pos', 'Visa', 'Visa Ready POS', $a, 'schemes'),
        pos_m('visa_net_pos', 'Visa', 'VisaNet Terminal', $i, 'schemes', $legacy),
        pos_m('visa_tap_to_phone', 'Visa', 'Tap to Phone', $s, 'schemes', ['nfc_hw' => true, 'chip' => false]),
        pos_m('visa_softpos', 'Visa', 'SoftPOS', $s, 'schemes', ['nfc_hw' => true, 'chip' => false]),
        pos_m('visa_paywave_pos', 'Visa', 'payWave POS', $a, 'schemes'),
        pos_m('mastercard_contactless', 'Mastercard', 'Contactless POS', $a, 'schemes'),
        pos_m('mastercard_tap_on_phone', 'Mastercard', 'Tap on Phone', $s, 'schemes', ['nfc_hw' => true, 'chip' => false]),
        pos_m('mastercard_softpos', 'Mastercard', 'SoftPOS', $s, 'schemes', ['nfc_hw' => true, 'chip' => false]),
        pos_m('mastercard_qrc', 'Mastercard', 'QR + Card POS', $a, 'schemes'),
        pos_m('amex_expresspay', 'American Express', 'ExpressPay POS', $a, 'schemes'),
        pos_m('amex_iccp', 'American Express', 'ICCP Terminal', $i, 'schemes', $legacy),
        pos_m('visa_adyen_terminal', 'Visa', 'Adyen-ready POS', $a, 'schemes'),
        pos_m('visa_cybersource_pos', 'Visa', 'CyberSource POS', $a, 'schemes'),
        pos_m('mastercard_mdi', 'Mastercard', 'MDI Terminal', $i, 'schemes', $legacy),
        pos_m('mastercard_cnp', 'Mastercard', 'Click to Pay POS', $s, 'schemes', ['chip' => false]),
        pos_m('unionpay_quickpass', 'UnionPay', 'QuickPass POS', $a, 'east_asia'),
        pos_m('jcb_j_smart', 'JCB', 'J-Smart POS', $a, 'east_asia'),
        pos_m('discover_dpass', 'Discover', 'D-PAS POS', $a, 'americas'),

        // ── Americas ──────────────────────────────────────────────
        pos_m('verifone_vx510', 'Verifone', 'VX 510', $v, 'americas', $legacy + ['os' => 'Verix']),
        pos_m('verifone_vx570', 'Verifone', 'VX 570', $v, 'americas', $legacy + ['os' => 'Verix']),
        pos_m('verifone_vx610', 'Verifone', 'VX 610', $v, 'americas', $legacy + ['os' => 'Verix']),
        pos_m('verifone_vx670', 'Verifone', 'VX 670', $v, 'americas', $legacy + ['os' => 'Verix']),
        pos_m('verifone_vx690', 'Verifone', 'VX 690', $v, 'americas', $legacy + ['os' => 'Verix']),
        pos_m('verifone_vx805', 'Verifone', 'VX 805', $v, 'americas', $legacy + ['os' => 'Verix']),
        pos_m('verifone_omni3750', 'Verifone', 'Omni 3750', $v, 'americas', $legacy),
        pos_m('verifone_omni3200', 'Verifone', 'Omni 3200', $v, 'americas', $legacy),
        pos_m('verifone_carbon', 'Verifone', 'Carbon 8', $a, 'americas'),
        pos_m('verifone_carbon10', 'Verifone', 'Carbon 10', $a, 'americas'),
        pos_m('verifone_e285', 'Verifone', 'e285', $a, 'americas'),
        pos_m('verifone_m400', 'Verifone', 'M400', $a, 'americas'),
        pos_m('verifone_p200', 'Verifone', 'P200', $a, 'americas'),
        pos_m('verifone_engage_v400c', 'Verifone', 'Engage V400c', $a, 'americas'),
        pos_m('verifone_engage_v200c', 'Verifone', 'Engage V200c', $a, 'americas'),
        pos_m('hypercom_t7', 'Hypercom', 'T7 Plus', $v, 'americas', $legacy),
        pos_m('hypercom_t4220', 'Hypercom', 'T4220', $v, 'americas', $legacy),
        pos_m('nurit_8320', 'Lipman', 'Nurit 8320', $v, 'americas', $legacy),
        pos_m('clover_mini', 'Clover', 'Mini', $a, 'americas'),
        pos_m('clover_flex', 'Clover', 'Flex', $a, 'americas'),
        pos_m('clover_station', 'Clover', 'Station', $a, 'americas'),
        pos_m('clover_duo', 'Clover', 'Station Duo', $a, 'americas'),
        pos_m('clover_go', 'Clover', 'Go', $t, 'americas', ['chip' => false]),
        pos_m('clover_compact', 'Clover', 'Compact', $a, 'americas'),
        pos_m('square_terminal', 'Square', 'Terminal', $a, 'americas'),
        pos_m('square_register', 'Square', 'Register', $a, 'americas'),
        pos_m('square_stand', 'Square', 'Stand', $t, 'americas'),
        pos_m('square_reader', 'Square', 'Reader', $t, 'americas', ['chip' => false]),
        pos_m('dejavoo_z8', 'Dejavoo', 'Z8', $a, 'americas'),
        pos_m('dejavoo_z9', 'Dejavoo', 'Z9', $a, 'americas'),
        pos_m('dejavoo_z11', 'Dejavoo', 'Z11', $a, 'americas'),
        pos_m('dejavoo_qd4', 'Dejavoo', 'QD4', $a, 'americas'),
        pos_m('dejavoo_p1', 'Dejavoo', 'P1', $a, 'americas'),
        pos_m('firstdata_fd150', 'First Data', 'FD150', $i, 'americas', $legacy),
        pos_m('firstdata_fd130', 'First Data', 'FD130', $i, 'americas', $legacy),
        pos_m('firstdata_fd35', 'First Data', 'FD35', $a, 'americas'),
        pos_m('valor_vl100', 'Valor', 'VL100', $a, 'americas'),
        pos_m('valor_vl110', 'Valor', 'VL110', $a, 'americas'),
        pos_m('shift4_skytab', 'Shift4', 'SkyTab', $a, 'americas'),
        pos_m('ncr_silver', 'NCR', 'Silver', $a, 'americas'),
        pos_m('elo_paypoint', 'Elo', 'PayPoint', $a, 'americas'),
        pos_m('equinox_l5300', 'Equinox', 'L5300', $i, 'americas', $legacy),
        pos_m('magtek_dynaflex', 'MagTek', 'DynaFlex', $t, 'americas'),
        pos_m('bbpos_wisepos_e', 'BBPOS', 'WisePOS E', $a, 'americas'),
        pos_m('bbpos_chipper', 'BBPOS', 'Chipper 2X', $t, 'americas'),

        // ── Europe ────────────────────────────────────────────────
        pos_m('ingenico_ict220', 'Ingenico', 'iCT220', $i, 'europe', $legacy),
        pos_m('ingenico_ict250', 'Ingenico', 'iCT250', $i, 'europe', $legacy),
        pos_m('ingenico_iwl220', 'Ingenico', 'iWL220', $i, 'europe', $legacy),
        pos_m('ingenico_iwl250', 'Ingenico', 'iWL250', $i, 'europe', $legacy),
        pos_m('ingenico_iup250', 'Ingenico', 'iUP250', $i, 'europe', $legacy),
        pos_m('ingenico_move2500', 'Ingenico', 'Move 2500', $i, 'europe'),
        pos_m('ingenico_move3500', 'Ingenico', 'Move 3500', $i, 'europe'),
        pos_m('ingenico_desk3500', 'Ingenico', 'Desk 3500', $i, 'europe'),
        pos_m('ingenico_lane3600', 'Ingenico', 'Lane 3600', $i, 'europe'),
        pos_m('ingenico_lane5000', 'Ingenico', 'Lane 5000', $i, 'europe'),
        pos_m('ingenico_lane7000', 'Ingenico', 'Lane 7000', $i, 'europe'),
        pos_m('ingenico_self2000', 'Ingenico', 'Self 2000', $a, 'europe'),
        pos_m('ingenico_link2500', 'Ingenico', 'Link 2500', $t, 'europe'),
        pos_m('ingenico_dx4000', 'Ingenico', 'AXIUM DX4000', $a, 'europe'),
        pos_m('ingenico_dx6000', 'Ingenico', 'AXIUM DX6000', $a, 'europe'),
        pos_m('ingenico_ex8000', 'Ingenico', 'AXIUM EX8000', $a, 'europe'),
        pos_m('worldline_yomani', 'Worldline', 'Yomani', $i, 'europe', $legacy),
        pos_m('worldline_yomani_xr', 'Worldline', 'Yomani XR', $a, 'europe'),
        pos_m('worldline_valina', 'Worldline', 'Valina', $a, 'europe'),
        pos_m('worldline_xenteo', 'Worldline', 'XENTEO', $i, 'europe', $legacy),
        pos_m('worldline_portable', 'Worldline', 'Portable', $a, 'europe'),
        pos_m('nexi_smartpos', 'Nexi', 'SmartPOS', $a, 'europe'),
        pos_m('sumup_solo', 'SumUp', 'Solo', $a, 'europe'),
        pos_m('sumup_air', 'SumUp', 'Air', $t, 'europe', ['chip' => false]),
        pos_m('sumup_3g', 'SumUp', '3G', $t, 'europe'),
        pos_m('zettle_reader', 'Zettle', 'Reader', $t, 'europe'),
        pos_m('zettle_terminal', 'Zettle', 'Terminal', $a, 'europe'),
        pos_m('spire_spc5', 'Spire', 'SPc5', $i, 'europe', $legacy),
        pos_m('spire_spw70', 'Spire', 'SPw70', $i, 'europe', $legacy),
        pos_m('datecs_fp700', 'Datecs', 'FP-700', $a, 'europe'),
        pos_m('datecs_bluepad50', 'Datecs', 'BluePad-50', $t, 'europe'),
        pos_m('castles_vega3000', 'Castles', 'VEGA 3000', $a, 'europe'),
        pos_m('castles_saturn1000', 'Castles', 'SATURN 1000', $i, 'europe', $legacy),

        // ── Gulf ──────────────────────────────────────────────────
        pos_m('bitel_ic5000', 'Bitel', 'IC5000', $a, 'gulf'),
        pos_m('bitel_ic3200', 'Bitel', 'IC3200', $a, 'gulf'),
        pos_m('geidea_m3', 'Geidea', 'M3', $a, 'gulf'),
        pos_m('geidea_smart', 'Geidea', 'Smart POS', $a, 'gulf'),
        pos_m('geidea_softpos', 'Geidea', 'SoftPOS', $s, 'gulf', ['chip' => false]),
        pos_m('ngenius_pos', 'Network International', 'N-Genius POS', $a, 'gulf'),
        pos_m('ngenius_softpos', 'Network International', 'N-Genius SoftPOS', $s, 'gulf', ['chip' => false]),
        pos_m('magnati_pos', 'Magnati', 'POS', $a, 'gulf'),
        pos_m('nearpay_softpos', 'NearPay', 'SoftPOS', $s, 'gulf', ['chip' => false]),
        pos_m('paymob_pos', 'Paymob', 'Smart POS', $a, 'gulf'),
        pos_m('tap_pos', 'Tap', 'Tap POS', $a, 'gulf'),
        pos_m('telr_pos', 'Telr', 'POS', $a, 'gulf'),
        pos_m('stc_pay_pos', 'STC Pay', 'Merchant POS', $a, 'gulf'),
        pos_m('mada_pos', 'mada', 'mada POS', $a, 'gulf'),
        pos_m('hyperpay_pos', 'HyperPay', 'POS', $a, 'gulf'),
        pos_m('checkout_pos', 'Checkout.com', 'Smart POS', $a, 'gulf'),
        pos_m('bitel_ic8000', 'Bitel', 'IC8000', $a, 'gulf'),
        pos_m('bitel_ic2100', 'Bitel', 'IC2100', $a, 'gulf', $legacy),
        pos_m('mada_softpos', 'mada', 'mada SoftPOS', $s, 'gulf', ['chip' => false]),
        pos_m('knet_pos', 'KNET', 'KNET POS', $a, 'gulf'),
        pos_m('benefit_pos', 'Benefit', 'Benefit POS', $a, 'gulf'),
        pos_m('omannet_pos', 'OmanNet', 'OmanNet POS', $a, 'gulf'),
        pos_m('qpay_pos', 'QPay', 'QPay POS', $a, 'gulf'),
        pos_m('alrajhi_pos', 'Al Rajhi', 'Merchant POS', $a, 'gulf'),
        pos_m('alinma_pos', 'Alinma', 'POS', $a, 'gulf'),
        pos_m('snb_pos', 'SNB', 'POS', $a, 'gulf'),
        pos_m('adcb_pos', 'ADCB', 'POS', $a, 'gulf'),
        pos_m('enbd_pos', 'Emirates NBD', 'POS', $a, 'gulf'),
        pos_m('fab_pos', 'FAB', 'POS', $a, 'gulf'),
        pos_m('mashreq_pos', 'Mashreq', 'POS', $a, 'gulf'),
        pos_m('qnb_pos', 'QNB', 'POS', $a, 'gulf'),
        pos_m('nbk_pos', 'NBK', 'POS', $a, 'gulf'),
        pos_m('bankmuscat_pos', 'Bank Muscat', 'POS', $a, 'gulf'),
        pos_m('castles_vega3000_gulf', 'Castles', 'VEGA 3000 GCC', $a, 'gulf'),
        pos_m('hala_smart', 'HALA', 'Smart POS', $a, 'gulf', ['detect' => ['hala']]),
        pos_m('hala_a920', 'HALA', 'A920', $a, 'gulf'),
        pos_m('hala_handheld', 'HALA', 'Handheld', $a, 'gulf'),
        pos_m('hala_softpos', 'HALA', 'SoftPOS', $s, 'gulf', ['chip' => false]),
        pos_m('pax_s920', 'PAX', 'S920', $a, 'gulf'),
        pos_m('pax_d180', 'PAX', 'D180', $a, 'gulf', $legacy),

        // ── East Asia ─────────────────────────────────────────────
        pos_m('pax_s80', 'PAX', 'S80', $i, 'east_asia', $legacy),
        pos_m('pax_s90', 'PAX', 'S90', $i, 'east_asia', $legacy),
        pos_m('pax_s300', 'PAX', 'S300', $i, 'east_asia', $legacy),
        pos_m('pax_s800', 'PAX', 'S800', $i, 'east_asia', $legacy),
        pos_m('pax_d210', 'PAX', 'D210', $i, 'east_asia', $legacy),
        pos_m('pax_d230', 'PAX', 'D230', $a, 'east_asia'),
        pos_m('pax_e500', 'PAX', 'E500', $a, 'east_asia'),
        pos_m('pax_a8900', 'PAX', 'A8900', $a, 'east_asia'),
        pos_m('pax_a920pro', 'PAX', 'A920 Pro', $a, 'east_asia'),
        pos_m('pax_a6650', 'PAX', 'A6650', $a, 'east_asia'),
        pos_m('newland_n700', 'Newland', 'N700', $a, 'east_asia'),
        pos_m('newland_me31', 'Newland', 'ME31', $a, 'east_asia'),
        pos_m('newland_me50c', 'Newland', 'ME50C', $a, 'east_asia'),
        pos_m('landi_a8', 'Landi', 'A8', $a, 'east_asia'),
        pos_m('landi_mpos', 'Landi', 'mPOS', $t, 'east_asia'),
        pos_m('vanstone_a8', 'Vanstone', 'A8', $a, 'east_asia'),
        pos_m('centerm_k9', 'Centerm', 'K9', $a, 'east_asia'),
        pos_m('wiseasy_p5', 'Wiseasy', 'P5', $a, 'east_asia'),
        pos_m('imin_swan', 'iMin', 'Swan', $a, 'east_asia'),
        pos_m('imin_d4', 'iMin', 'D4', $a, 'east_asia'),
        pos_m('ciontek_cs20', 'Ciontek', 'CS20', $a, 'east_asia'),
        pos_m('ciontek_cs50', 'Ciontek', 'CS50', $a, 'east_asia'),
        pos_m('urovo_i9100', 'Urovo', 'i9100', $a, 'east_asia'),
        pos_m('telpo_tps900', 'Telpo', 'TPS900', $a, 'east_asia'),
        pos_m('telpo_m1', 'Telpo', 'M1', $a, 'east_asia'),
        pos_m('morefun_h9', 'MoreFun', 'H9', $a, 'east_asia'),
        pos_m('nexgo_n5', 'Nexgo', 'N5', $a, 'east_asia'),
        pos_m('nexgo_n96', 'Nexgo', 'N96', $a, 'east_asia'),
        pos_m('sunmi_p3', 'Sunmi', 'P3', $a, 'east_asia'),
        pos_m('sunmi_v2', 'Sunmi', 'V2', $a, 'east_asia'),
        pos_m('sunmi_v3', 'Sunmi', 'V3', $a, 'east_asia'),
        pos_m('sunmi_v3_mix', 'Sunmi', 'V3 MIX', $a, 'east_asia'),
        pos_m('sunmi_t2', 'Sunmi', 'T2', $a, 'east_asia'),
        pos_m('castles_s1d2', 'Castles', 'S1D2', $a, 'east_asia'),

        // ── India ─────────────────────────────────────────────────
        pos_m('pinelabs_plutus', 'Pine Labs', 'Plutus', $a, 'india'),
        pos_m('pinelabs_android', 'Pine Labs', 'Android POS', $a, 'india'),
        pos_m('ezetap_a910', 'Ezetap', 'A910', $a, 'india'),
        pos_m('ezetap_smart', 'Ezetap', 'Smart POS', $a, 'india'),
        pos_m('mswipe_wisepad', 'Mswipe', 'WisePad', $t, 'india'),
        pos_m('mswipe_android', 'Mswipe', 'Android POS', $a, 'india'),
        pos_m('paytm_edc', 'Paytm', 'EDC', $a, 'india'),
        pos_m('paytm_soundbox', 'Paytm', 'All-in-One', $a, 'india'),
        pos_m('bharatpe_pos', 'BharatPe', 'POS', $a, 'india'),
        pos_m('phonepe_pos', 'PhonePe', 'POS', $a, 'india'),
        pos_m('innoviti_pos', 'Innoviti', 'POS', $a, 'india'),
        pos_m('mosambee_pos', 'Mosambee', 'POS', $a, 'india'),
        pos_m('hitachi_im30', 'Hitachi Payments', 'IM-30', $a, 'india'),
        pos_m('worldline_india', 'Worldline India', 'Yomani', $i, 'india', $legacy),
        pos_m('razorpay_pos', 'Razorpay', 'POS', $a, 'india'),
        pos_m('mixn_mpos', 'Mixn', 'mPOS', $t, 'india'),

        // ── Africa ────────────────────────────────────────────────
        pos_m('interswitch_cgate', 'Interswitch', "C'Gate", $a, 'africa'),
        pos_m('moniepoint_pos', 'Moniepoint', 'Android POS', $a, 'africa'),
        pos_m('paystack_terminal', 'Paystack', 'Terminal', $a, 'africa'),
        pos_m('opay_pos', 'OPay', 'POS', $a, 'africa'),
        pos_m('palmpay_pos', 'PalmPay', 'POS', $a, 'africa'),
        pos_m('flutterwave_pos', 'Flutterwave', 'POS', $a, 'africa'),
        pos_m('kopo_pos', 'Kopo Kopo', 'POS', $a, 'africa'),
        pos_m('equity_pos', 'Equity', 'POS', $a, 'africa'),
        pos_m('fnb_speedpoint', 'FNB', 'Speedpoint', $i, 'africa', $legacy),
        pos_m('standardbank_pos', 'Standard Bank', 'POS', $i, 'africa', $legacy),
        pos_m('absa_pos', 'Absa', 'POS', $a, 'africa'),
        pos_m('cellulant_pos', 'Cellulant', 'POS', $a, 'africa'),
        pos_m('mpesa_lipa', 'Safaricom', 'Lipa na M-PESA POS', $a, 'africa'),
        pos_m('mtn_momo_pos', 'MTN', 'MoMo POS', $a, 'africa'),
        pos_m('airtel_money_pos', 'Airtel', 'Airtel Money POS', $a, 'africa'),
        pos_m('vodacom_mpesa_pos', 'Vodacom', 'M-Pesa POS', $a, 'africa'),
        pos_m('capitec_pos', 'Capitec', 'POS', $a, 'africa'),
        pos_m('nedbank_pos', 'Nedbank', 'POS', $i, 'africa', $legacy),
        pos_m('fnb_epos', 'FNB', 'ePOS', $a, 'africa'),
        pos_m('accessbank_pos', 'Access Bank', 'POS', $a, 'africa'),
        pos_m('gtbank_pos', 'GTBank', 'POS', $a, 'africa'),
        pos_m('zenith_pos', 'Zenith', 'POS', $a, 'africa'),
        pos_m('firstbank_pos', 'FirstBank', 'POS', $a, 'africa'),
        pos_m('chipper_pos', 'Chipper Cash', 'POS', $a, 'africa'),
        pos_m('wave_pos', 'Wave', 'POS', $a, 'africa'),

        pos_m('hdfc_smarthub', 'HDFC', 'SmartHub POS', $a, 'india'),
        pos_m('axis_pos', 'Axis Bank', 'POS', $a, 'india'),
        pos_m('sbi_pos', 'SBI', 'YONO POS', $a, 'india'),
        pos_m('icici_pos', 'ICICI', 'POS', $a, 'india'),
        pos_m('kotak_pos', 'Kotak', 'POS', $a, 'india'),
        pos_m('yesbank_pos', 'Yes Bank', 'POS', $a, 'india'),
        pos_m('bharatqr_pos', 'NPCI', 'BharatQR POS', $a, 'india'),
        pos_m('upi_softpos', 'NPCI', 'UPI SoftPOS', $s, 'india', ['chip' => false]),

        pos_m('verifone_ux300', 'Verifone', 'UX 300', $a, 'europe'),
        pos_m('verifone_ux100', 'Verifone', 'UX 100', $a, 'europe'),
        pos_m('ingenico_ipp320', 'Ingenico', 'iPP320', $i, 'europe', $legacy),
        pos_m('ingenico_ipp350', 'Ingenico', 'iPP350', $i, 'europe', $legacy),
        pos_m('ingenico_isc250', 'Ingenico', 'iSC250', $i, 'americas', $legacy),
        pos_m('ingenico_isc480', 'Ingenico', 'iSC480', $i, 'americas', $legacy),
        pos_m('ingenico_iuc285', 'Ingenico', 'iUC285', $i, 'americas', $legacy),
        pos_m('verifone_mx915', 'Verifone', 'MX 915', $v, 'americas', $legacy),
        pos_m('verifone_mx925', 'Verifone', 'MX 925', $v, 'americas', $legacy),
        pos_m('verifone_commander', 'Verifone', 'Commander', $v, 'americas', $legacy),
        pos_m('toast_pos', 'Toast', 'Toast POS', $a, 'americas'),
        pos_m('shopify_pos', 'Shopify', 'POS Terminal', $a, 'americas'),
        pos_m('stripe_terminal_s700', 'Stripe', 'S700', $a, 'americas'),
        pos_m('stripe_terminal_m2', 'Stripe', 'Reader M2', $t, 'americas'),
        pos_m('stripe_tap_to_pay', 'Stripe', 'Tap to Pay', $s, 'americas', ['chip' => false]),
        pos_m('pax_a50', 'PAX', 'A50', $a, 'east_asia'),
        pos_m('pax_a60', 'PAX', 'A60', $a, 'east_asia'),
        pos_m('pax_a3700', 'PAX', 'A3700', $a, 'east_asia'),
        pos_m('topwise_t1', 'Topwise', 'T1', $a, 'east_asia'),
        pos_m('alipay_pos', 'Alipay', 'Merchant POS', $a, 'east_asia'),
        pos_m('wechat_pos', 'WeChat Pay', 'Merchant POS', $a, 'east_asia'),
        pos_m('ncr_aloha', 'NCR', 'Aloha POS', $a, 'americas'),
        pos_m('oracle_micros', 'Oracle', 'MICROS Workstation', $a, 'americas'),
    ];
}

function pos_device_select_options(string $selected = '', bool $ar = false): string
{
    $html = '';
    foreach (pos_device_catalog_grouped() as $group) {
        $label = $ar ? (string) $group['label_ar'] : (string) $group['label_en'];
        $html .= '<optgroup label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">';
        foreach ($group['items'] as $key => $dev) {
            $sel = $selected === $key ? ' selected' : '';
            $star = !empty($dev['custom']) ? ' ★' : '';
            $html .= '<option value="' . htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>'
                . htmlspecialchars((string) ($dev['label'] ?? $key), ENT_QUOTES, 'UTF-8') . $star . '</option>';
        }
        $html .= '</optgroup>';
    }
    return $html;
}

function pos_device_catalog_grouped(): array
{
    $groups = [];
    foreach (pos_device_regions() as $key => $lab) {
        $groups[$key] = ['label_ar' => $lab['ar'], 'label_en' => $lab['en'], 'items' => []];
    }
    foreach (pos_device_catalog() as $code => $dev) {
        $region = (string) ($dev['region'] ?? 'global');
        if (!isset($groups[$region])) {
            $region = 'global';
        }
        $groups[$region]['items'][$code] = $dev;
    }
    return array_filter($groups, static function ($g) {
        return $g['items'] !== [];
    });
}
