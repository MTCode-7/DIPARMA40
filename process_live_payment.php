{
  "gateways": [
    {
      "code": "stripe",
      "name": "Stripe",
      "type": "card",
      "class_name": "StripeGateway",
      "countries": ["US", "CA", "UK", "AE", "SA", "EG", "JO", "KW", "QA", "OM", "BH"],
      "currencies": ["USD", "EUR", "GBP", "AED", "SAR", "EGP", "JOD", "KWD", "QAR", "OMR", "BHD"]
    },
    {
      "code": "paypal",
      "name": "PayPal",
      "type": "card",
      "class_name": "PayPalGateway",
      "countries": ["US", "CA", "UK", "AU", "AE", "SA", "EG", "JO", "KW", "QA", "OM", "BH"],
      "currencies": ["USD", "EUR", "GBP", "AUD", "CAD", "AED", "SAR", "EGP", "JOD", "KWD", "QAR", "OMR", "BHD"]
    },
    {
      "code": "wise",
      "name": "Wise",
      "type": "bank",
      "class_name": "WiseGateway",
      "countries": ["US", "UK", "AE", "SA", "EG", "JO", "KW", "QA", "OM", "BH", "IN", "PK", "BD"],
      "currencies": ["USD", "EUR", "GBP", "AED", "SAR", "EGP", "JOD", "KWD", "QAR", "OMR", "BHD", "INR", "PKR", "BDT"]
    },
    {
      "code": "binance",
      "name": "Binance",
      "type": "crypto",
      "class_name": "BinanceGateway",
      "countries": ["*"],
      "currencies": ["BTC", "ETH", "USDT", "BNB", "ADA", "DOGE", "XRP", "SOL"]
    },
    {
      "code": "myfattoorah",
      "name": "MyFattoorah",
      "type": "regional",
      "class_name": "MyFattoorahGateway",
      "countries": ["AE", "SA", "EG", "JO", "KW", "QA", "OM", "BH"],
      "currencies": ["AED", "SAR", "EGP", "JOD", "KWD", "QAR", "OMR", "BHD"]
    },
    {
      "code": "payfort",
      "name": "PayFort",
      "type": "regional",
      "class_name": "PayFortGateway",
      "countries": ["AE", "SA", "EG", "JO", "KW", "QA", "OM", "BH"],
      "currencies": ["AED", "SAR", "EGP", "JOD", "KWD", "QAR", "OMR", "BHD"]
    },
    {
      "code": "hyperpay",
      "name": "HyperPay",
      "type": "regional",
      "class_name": "HyperPayGateway",
      "countries": ["AE", "SA", "EG", "JO", "KW", "QA", "OM", "BH"],
      "currencies": ["AED", "SAR", "EGP", "JOD", "KWD", "QAR", "OMR", "BHD"]
    },
    {
      "code": "tap",
      "name": "Tap Payments",
      "type": "regional",
      "class_name": "TapGateway",
      "countries": ["AE", "SA", "EG", "JO", "KW", "QA", "OM", "BH"],
      "currencies": ["AED", "SAR", "EGP", "JOD", "KWD", "QAR", "OMR", "BHD"]
    },
    {
      "code": "paytabs",
      "name": "PayTabs",
      "type": "regional",
      "class_name": "PayTabsGateway",
      "countries": ["AE", "SA", "EG", "JO", "KW", "QA", "OM", "BH"],
      "currencies": ["AED", "SAR", "EGP", "JOD", "KWD", "QAR", "OMR", "BHD"]
    },
    {
      "code": "adyen",
      "name": "Adyen",
      "type": "card",
      "class_name": "AdyenGateway",
      "countries": ["*"],
      "currencies": ["*"]
    },
    {
      "code": "braintree",
      "name": "Braintree",
      "type": "card",
      "class_name": "BraintreeGateway",
      "countries": ["US", "CA", "UK", "AU"],
      "currencies": ["USD", "EUR", "GBP", "AUD", "CAD"]
    },
    {
      "code": "coinbase",
      "name": "Coinbase Commerce",
      "type": "crypto",
      "class_name": "CoinbaseGateway",
      "countries": ["*"],
      "currencies": ["BTC", "ETH", "USDC", "DAI", "LTC", "BCH"]
    },
    {
      "code": "bitpay",
      "name": "BitPay",
      "type": "crypto",
      "class_name": "BitPayGateway",
      "countries": ["*"],
      "currencies": ["BTC", "ETH", "USDC", "BCH", "XRP", "DOGE"]
    },
    {
      "code": "skrill",
      "name": "Skrill",
      "type": "wallet",
      "class_name": "SkrillGateway",
      "countries": ["*"],
      "currencies": ["USD", "EUR", "GBP", "AED", "SAR", "EGP"]
    },
    {
      "code": "neteller",
      "name": "Neteller",
      "type": "wallet",
      "class_name": "NetellerGateway",
      "countries": ["*"],
      "currencies": ["USD", "EUR", "GBP", "AED", "SAR", "EGP"]
    },
    {
      "code": "webmoney",
      "name": "WebMoney",
      "type": "wallet",
      "class_name": "WebMoneyGateway",
      "countries": ["*"],
      "currencies": ["USD", "EUR", "RUB"]
    },
    {
      "code": "mashreq",
      "name": "Mashreq Bank",
      "type": "bank",
      "class_name": "BankTransferGateway",
      "countries": ["AE"],
      "currencies": ["AED", "USD"]
    },
    {
      "code": "hsbc",
      "name": "HSBC UAE",
      "type": "bank",
      "class_name": "BankTransferGateway",
      "countries": ["AE"],
      "currencies": ["AED", "USD"]
    },
    {
      "code": "nbe",
      "name": "NBE Egypt",
      "type": "bank",
      "class_name": "BankTransferGateway",
      "countries": ["EG"],
      "currencies": ["EGP", "USD"]
    },
    {
      "code": "jpmorgan",
      "name": "JP Morgan Chase",
      "type": "bank",
      "class_name": "BankTransferGateway",
      "countries": ["US"],
      "currencies": ["USD"]
    },
    {
      "code": "gateio",
      "name": "Gate.io",
      "type": "crypto",
      "class_name": "CryptoGateway",
      "countries": ["*"],
      "currencies": ["USDT", "BTC", "ETH", "GT"]
    },
    {
      "code": "payram",
      "name": "PayRam",
      "type": "crypto",
      "class_name": "CryptoGateway",
      "countries": ["*"],
      "currencies": ["BTC", "ETH", "USDT", "BNB", "ADA", "DOGE"]
    },
    {
      "code": "nuvei",
      "name": "Nuvei",
      "type": "card",
      "class_name": "NuveiGateway",
      "countries": ["*"],
      "currencies": ["*"]
    },
    {
      "code": "diparma",
      "name": "DI PARMA",
      "type": "card",
      "class_name": "DIParmaGateway",
      "countries": ["*"],
      "currencies": ["*"]
    }
  ]
}