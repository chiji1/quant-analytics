import urllib.request
import json
import ssl

ctx = ssl.create_default_context()
ctx.check_hostname = False
ctx.verify_mode = ssl.CERT_NONE

req = urllib.request.Request("https://fapi.binance.com/fapi/v1/exchangeInfo")
with urllib.request.urlopen(req, context=ctx) as response:
    data = json.loads(response.read().decode('utf-8'))
    sym = next((s for s in data["symbols"] if s["symbol"] == "BTCUSDT"), None)
    if sym:
        for f in sym["filters"]:
            if f["filterType"] == "MIN_NOTIONAL":
                print(json.dumps(f))
