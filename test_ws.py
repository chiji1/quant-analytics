import asyncio
import json
import websockets

async def test():
    async with websockets.connect("wss://stream.binancefuture.com/ws/btcusdt@depth20@100ms") as ws:
        msg = await ws.recv()
        print(json.dumps(json.loads(msg), indent=2))
        
asyncio.run(test())
