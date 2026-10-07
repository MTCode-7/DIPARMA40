import hashlib

KEYS = [
    "SQUARE_APPLICATION_ID",
    "SQUARE_LOCATION_ID",
    "SQUARE_ACCESS_TOKEN",
    "SQUARE_ENVIRONMENT",
    "SQUARE_API_KEY",
    "SQUARE_SECRET_KEY",
]
LOCAL = {
    "SQUARE_APPLICATION_ID": ("4a7e1d94c5a6", 29),
    "SQUARE_ACCESS_TOKEN": ("4bf386c5ccd5", 64),
    "SQUARE_LOCATION_ID": ("e28553120617", 13),
    "SQUARE_ENVIRONMENT": ("ab8e18ef4ebe", 10),
}


def parse_env(path):
    out = {}
    with open(path, "r", encoding="utf-8", errors="replace") as handle:
        for line in handle:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, value = line.split("=", 1)
            out[key.strip()] = value.strip().strip('"').strip("'")
    return out


def sha12(value):
    return hashlib.sha256(value.encode("utf-8")).hexdigest()[:12]


def report(label, path):
    print("=== %s ===" % label)
    try:
        vals = parse_env(path)
    except Exception as exc:
        print("read_error", type(exc).__name__)
        return False
    matched = True
    for key in KEYS:
        if key not in vals:
            print("%s MISSING" % key)
            if key in LOCAL:
                matched = False
            continue
        value = vals[key]
        digest = sha12(value)
        expected = LOCAL.get(key)
        if expected:
            status = "MATCH" if digest == expected[0] and len(value) == expected[1] else "DIFF"
            if status != "MATCH":
                matched = False
        else:
            status = "n/a"
        print("%s sha256_12=%s len=%d vs_local=%s" % (key, digest, len(value), status))
    return matched


html_ok = report("PROD_HTML_.env", "/var/www/html/DIPARMA40/.env")
nginx_ok = report("PROD_NGINX_.env", "/var/www/diparma/.env")
print("ENV_HTML_MATCH" if html_ok else "ENV_HTML_DIFF")
print("ENV_NGINX_MATCH" if nginx_ok else "ENV_NGINX_DIFF")
