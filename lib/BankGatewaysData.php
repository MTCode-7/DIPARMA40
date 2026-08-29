<?php
/**
 * DI PARMA | قائمة البنوك العالمية
 * أمريكا - الصين - إندونيسيا - الخليج - الهند - بنغلاديش
 */

function getBanksData(): array {
    return [

    /* ═══════════════════════════════ 🇺🇸 أمريكا ══════════════════════════════ */
    'jpmorgan_chase'      => ['name'=>'JPMorgan Chase','local'=>'جي بي مورغان تشيس','flag'=>'🇺🇸','region'=>'أمريكا','country'=>'US','swift'=>'CHASUS33','currencies'=>['USD','EUR','GBP'],'fields'=>['routing_number','account_number','account_type','account_holder','swift_code','bank_address']],
    'bank_of_america'     => ['name'=>'Bank of America','local'=>'بنك أوف أمريكا','flag'=>'🇺🇸','region'=>'أمريكا','country'=>'US','swift'=>'BOFAUS3N','currencies'=>['USD','EUR'],'fields'=>['routing_number','account_number','account_type','account_holder','swift_code']],
    'wells_fargo'         => ['name'=>'Wells Fargo','local'=>'ويلز فارغو','flag'=>'🇺🇸','region'=>'أمريكا','country'=>'US','swift'=>'WFBIUS6S','currencies'=>['USD'],'fields'=>['routing_number','account_number','account_type','account_holder','swift_code']],
    'citibank'            => ['name'=>'Citibank','local'=>'سيتي بنك','flag'=>'🇺🇸','region'=>'أمريكا','country'=>'US','swift'=>'CITIUS33','currencies'=>['USD','EUR','GBP','AED'],'fields'=>['routing_number','account_number','account_type','account_holder','swift_code','bank_address']],
    'us_bancorp'          => ['name'=>'U.S. Bancorp','local'=>'يو إس بانكورب','flag'=>'🇺🇸','region'=>'أمريكا','country'=>'US','swift'=>'USBKUS44','currencies'=>['USD'],'fields'=>['routing_number','account_number','account_type','account_holder','swift_code']],
    'goldman_sachs'       => ['name'=>'Goldman Sachs','local'=>'غولدمان ساكس','flag'=>'🇺🇸','region'=>'أمريكا','country'=>'US','swift'=>'GSCOUSS1','currencies'=>['USD','EUR'],'fields'=>['routing_number','account_number','account_holder','swift_code']],
    'morgan_stanley'      => ['name'=>'Morgan Stanley','local'=>'مورغان ستانلي','flag'=>'🇺🇸','region'=>'أمريكا','country'=>'US','swift'=>'MSNYUS33','currencies'=>['USD','EUR'],'fields'=>['routing_number','account_number','account_holder','swift_code']],
    'td_bank'             => ['name'=>'TD Bank','local'=>'تي دي بنك','flag'=>'🇺🇸','region'=>'أمريكا','country'=>'US','swift'=>'NRTHUS33','currencies'=>['USD','CAD'],'fields'=>['routing_number','account_number','account_type','account_holder','swift_code']],

    /* ═══════════════════════════════ 🇨🇳 الصين ═══════════════════════════════ */
    'icbc'                    => ['name'=>'ICBC','local'=>'البنك الصناعي والتجاري الصيني','flag'=>'🇨🇳','region'=>'الصين','country'=>'CN','swift'=>'ICBKCNBJ','currencies'=>['CNY','USD','EUR'],'fields'=>['account_number','account_holder','bank_name','bank_branch','swift_code','cnaps_code']],
    'china_construction_bank' => ['name'=>'China Construction Bank','local'=>'بنك الإعمار الصيني','flag'=>'🇨🇳','region'=>'الصين','country'=>'CN','swift'=>'PCBCCNBJ','currencies'=>['CNY','USD'],'fields'=>['account_number','account_holder','bank_branch','swift_code','cnaps_code']],
    'agricultural_bank_china' => ['name'=>'Agricultural Bank of China','local'=>'بنك الزراعة الصيني','flag'=>'🇨🇳','region'=>'الصين','country'=>'CN','swift'=>'ABOCCNBJ','currencies'=>['CNY','USD'],'fields'=>['account_number','account_holder','bank_branch','swift_code','cnaps_code']],
    'bank_of_china'           => ['name'=>'Bank of China','local'=>'بنك الصين','flag'=>'🇨🇳','region'=>'الصين','country'=>'CN','swift'=>'BKCHCNBJ','currencies'=>['CNY','USD','EUR','HKD'],'fields'=>['account_number','account_holder','bank_branch','swift_code','cnaps_code']],
    'bank_of_communications'  => ['name'=>'Bank of Communications','local'=>'بنك الاتصالات الصيني','flag'=>'🇨🇳','region'=>'الصين','country'=>'CN','swift'=>'COMMCNSH','currencies'=>['CNY','USD'],'fields'=>['account_number','account_holder','bank_branch','swift_code','cnaps_code']],
    'ping_an_bank'            => ['name'=>'Ping An Bank','local'=>'بنك بينغ آن','flag'=>'🇨🇳','region'=>'الصين','country'=>'CN','swift'=>'SZDBCNBS','currencies'=>['CNY','USD'],'fields'=>['account_number','account_holder','bank_branch','swift_code']],
    'china_merchants_bank'    => ['name'=>'China Merchants Bank','local'=>'بنك تجار الصين','flag'=>'🇨🇳','region'=>'الصين','country'=>'CN','swift'=>'CMBCCNBS','currencies'=>['CNY','USD','HKD'],'fields'=>['account_number','account_holder','bank_branch','swift_code','cnaps_code']],

    /* ══════════════════════════════ 🇮🇩 إندونيسيا ═══════════════════════════ */
    'bca'        => ['name'=>'Bank Central Asia (BCA)','local'=>'بنك سنترال آسيا','flag'=>'🇮🇩','region'=>'إندونيسيا','country'=>'ID','swift'=>'CENAIDJA','currencies'=>['IDR','USD'],'fields'=>['account_number','account_holder','bank_code','branch_code']],
    'bri'        => ['name'=>'Bank Rakyat Indonesia (BRI)','local'=>'بنك راكيات إندونيسيا','flag'=>'🇮🇩','region'=>'إندونيسيا','country'=>'ID','swift'=>'BRINIDJA','currencies'=>['IDR','USD'],'fields'=>['account_number','account_holder','bank_code','branch_code']],
    'mandiri'    => ['name'=>'Bank Mandiri','local'=>'بنك مانديري','flag'=>'🇮🇩','region'=>'إندونيسيا','country'=>'ID','swift'=>'BMRIIDJA','currencies'=>['IDR','USD','EUR'],'fields'=>['account_number','account_holder','bank_code','branch_code','swift_code']],
    'bni'        => ['name'=>'Bank Negara Indonesia (BNI)','local'=>'بنك نيغارا إندونيسيا','flag'=>'🇮🇩','region'=>'إندونيسيا','country'=>'ID','swift'=>'BNINIDJA','currencies'=>['IDR','USD'],'fields'=>['account_number','account_holder','bank_code','branch_code']],
    'cimb_niaga' => ['name'=>'CIMB Niaga','local'=>'سي آي إم بي نياغا','flag'=>'🇮🇩','region'=>'إندونيسيا','country'=>'ID','swift'=>'BNIAIDJA','currencies'=>['IDR','USD','SGD'],'fields'=>['account_number','account_holder','bank_code','branch_code']],
    'danamon'    => ['name'=>'Bank Danamon','local'=>'بنك داناموف','flag'=>'🇮🇩','region'=>'إندونيسيا','country'=>'ID','swift'=>'BDINIDJA','currencies'=>['IDR','USD'],'fields'=>['account_number','account_holder','bank_code','branch_code']],

    /* ══════════════════════════════ 🛢️ الخليج ════════════════════════════════ */
    'al_rajhi'     => ['name'=>'Al Rajhi Bank','local'=>'مصرف الراجحي','flag'=>'🇸🇦','region'=>'الخليج','country'=>'SA','swift'=>'RJHISARI','currencies'=>['SAR','USD'],'fields'=>['iban','account_number','account_holder','swift_code','bank_branch']],
    'snb'          => ['name'=>'Saudi National Bank','local'=>'البنك الأهلي السعودي','flag'=>'🇸🇦','region'=>'الخليج','country'=>'SA','swift'=>'NCBKSAJE','currencies'=>['SAR','USD','EUR'],'fields'=>['iban','account_number','account_holder','swift_code']],
    'riyad_bank'   => ['name'=>'Riyad Bank','local'=>'بنك الرياض','flag'=>'🇸🇦','region'=>'الخليج','country'=>'SA','swift'=>'RIBLSARI','currencies'=>['SAR','USD'],'fields'=>['iban','account_number','account_holder','swift_code']],
    'fab'          => ['name'=>'First Abu Dhabi Bank (FAB)','local'=>'بنك أبوظبي الأول','flag'=>'🇦🇪','region'=>'الخليج','country'=>'AE','swift'=>'NBADAEAA','currencies'=>['AED','USD','EUR','GBP'],'fields'=>['iban','account_number','account_holder','swift_code','bank_branch']],
    'adcb'         => ['name'=>'Abu Dhabi Commercial Bank','local'=>'بنك أبوظبي التجاري','flag'=>'🇦🇪','region'=>'الخليج','country'=>'AE','swift'=>'ADCBAEAA','currencies'=>['AED','USD'],'fields'=>['iban','account_number','account_holder','swift_code']],
    'emirates_nbd' => ['name'=>'Emirates NBD','local'=>'بنك الإمارات دبي الوطني','flag'=>'🇦🇪','region'=>'الخليج','country'=>'AE','swift'=>'EBILAEAD','currencies'=>['AED','USD','EUR','GBP'],'fields'=>['iban','account_number','account_holder','swift_code']],
    'nbk'          => ['name'=>'National Bank of Kuwait','local'=>'بنك الكويت الوطني','flag'=>'🇰🇼','region'=>'الخليج','country'=>'KW','swift'=>'NBOKKWKW','currencies'=>['KWD','USD','EUR'],'fields'=>['iban','account_number','account_holder','swift_code']],
    'qnb'          => ['name'=>'Qatar National Bank','local'=>'بنك قطر الوطني','flag'=>'🇶🇦','region'=>'الخليج','country'=>'QA','swift'=>'QNBAQAQA','currencies'=>['QAR','USD','EUR'],'fields'=>['iban','account_number','account_holder','swift_code']],
    'ahli_united'  => ['name'=>'Ahli United Bank','local'=>'بنك الأهلي المتحد','flag'=>'🇧🇭','region'=>'الخليج','country'=>'BH','swift'=>'AUBBBHBM','currencies'=>['BHD','USD'],'fields'=>['iban','account_number','account_holder','swift_code']],

    /* ════════════════════════════════ 🇮🇳 الهند ═══════════════════════════════ */
    'sbi'      => ['name'=>'State Bank of India (SBI)','local'=>'بنك الدولة الهندي','flag'=>'🇮🇳','region'=>'الهند','country'=>'IN','swift'=>'SBININBB','currencies'=>['INR','USD'],'fields'=>['account_number','account_holder','ifsc_code','bank_branch','swift_code']],
    'hdfc'     => ['name'=>'HDFC Bank','local'=>'بنك إتش دي إف سي','flag'=>'🇮🇳','region'=>'الهند','country'=>'IN','swift'=>'HDFCINBB','currencies'=>['INR','USD'],'fields'=>['account_number','account_holder','ifsc_code','bank_branch']],
    'icici'    => ['name'=>'ICICI Bank','local'=>'بنك آي سي آي سي آي','flag'=>'🇮🇳','region'=>'الهند','country'=>'IN','swift'=>'ICICINBB','currencies'=>['INR','USD','EUR'],'fields'=>['account_number','account_holder','ifsc_code','bank_branch','swift_code']],
    'axis_bank'=> ['name'=>'Axis Bank','local'=>'بنك أكسيس','flag'=>'🇮🇳','region'=>'الهند','country'=>'IN','swift'=>'AXISINBB','currencies'=>['INR','USD'],'fields'=>['account_number','account_holder','ifsc_code','bank_branch']],
    'kotak'    => ['name'=>'Kotak Mahindra Bank','local'=>'بنك كوتاك ماهيندرا','flag'=>'🇮🇳','region'=>'الهند','country'=>'IN','swift'=>'KKBKINBB','currencies'=>['INR','USD'],'fields'=>['account_number','account_holder','ifsc_code','bank_branch']],
    'punjab_national' => ['name'=>'Punjab National Bank','local'=>'بنك بنجاب الوطني','flag'=>'🇮🇳','region'=>'الهند','country'=>'IN','swift'=>'PUNBINBB','currencies'=>['INR','USD'],'fields'=>['account_number','account_holder','ifsc_code','bank_branch']],

    /* ══════════════════════════════ 🇧🇩 بنغلاديش ═════════════════════════════ */
    'sonali_bank'    => ['name'=>'Sonali Bank','local'=>'بنك سونالي','flag'=>'🇧🇩','region'=>'بنغلاديش','country'=>'BD','swift'=>'SONABDDA','currencies'=>['BDT','USD'],'fields'=>['account_number','account_holder','routing_number','bank_branch','swift_code']],
    'dutch_bangla'   => ['name'=>'Dutch-Bangla Bank','local'=>'البنك الهولندي البنغالي','flag'=>'🇧🇩','region'=>'بنغلاديش','country'=>'BD','swift'=>'DBBLBDDH','currencies'=>['BDT','USD'],'fields'=>['account_number','account_holder','routing_number','bank_branch']],
    'brac_bank'      => ['name'=>'BRAC Bank','local'=>'بنك براك','flag'=>'🇧🇩','region'=>'بنغلاديش','country'=>'BD','swift'=>'BRAKBDDH','currencies'=>['BDT','USD'],'fields'=>['account_number','account_holder','routing_number','bank_branch']],
    'islami_bank_bd' => ['name'=>'Islami Bank Bangladesh','local'=>'البنك الإسلامي بنغلاديش','flag'=>'🇧🇩','region'=>'بنغلاديش','country'=>'BD','swift'=>'IBBLBDDH','currencies'=>['BDT','USD'],'fields'=>['account_number','account_holder','routing_number','bank_branch','swift_code']],
    'prime_bank_bd'  => ['name'=>'Prime Bank Bangladesh','local'=>'بنك برايم بنغلاديش','flag'=>'🇧🇩','region'=>'بنغلاديش','country'=>'BD','swift'=>'PRBLBDDH','currencies'=>['BDT','USD'],'fields'=>['account_number','account_holder','routing_number','bank_branch']],
    'eastern_bank_bd'=> ['name'=>'Eastern Bank Bangladesh','local'=>'البنك الشرقي بنغلاديش','flag'=>'🇧🇩','region'=>'بنغلاديش','country'=>'BD','swift'=>'EBLDBDDH','currencies'=>['BDT','USD'],'fields'=>['account_number','account_holder','routing_number','bank_branch']],

    ]; // end return
}

function getBankFieldLabel(string $field): string {
    $map = [
        'account_number' => 'رقم الحساب',
        'account_holder' => 'اسم صاحب الحساب',
        'account_type'   => 'نوع الحساب',
        'routing_number' => 'رقم التوجيه (Routing)',
        'swift_code'     => 'SWIFT / BIC',
        'iban'           => 'IBAN',
        'bank_name'      => 'اسم البنك',
        'bank_branch'    => 'الفرع',
        'bank_address'   => 'عنوان البنك',
        'bank_code'      => 'كود البنك',
        'branch_code'    => 'كود الفرع',
        'ifsc_code'      => 'IFSC Code',
        'cnaps_code'     => 'CNAPS Code',
        'notes'          => 'ملاحظات',
    ];
    return $map[$field] ?? ucfirst(str_replace('_', ' ', $field));
}

function getBanksByRegion(): array {
    $out = [];
    foreach (getBanksData() as $code => $b) {
        $out[$b['region']][$code] = $b;
    }
    return $out;
}
