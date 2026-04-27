import React, { useMemo, useEffect } from 'react';
import { useTradingStore } from '../../stores/useTradingStore';

export default function OrderBookChart() {
    const { bids, asks } = useTradingStore((state) => state.orderBook);
    const hydrateOrderBook = useTradingStore((state) => state.hydrateOrderBook);
    const activeSymbols = useTradingStore((state) => state.riskParameters.allowed_symbols) || [];
    const storeDisplaySymbol = useTradingStore((state) => state.displaySymbol);
    const setDisplaySymbol = useTradingStore((state) => state.setDisplaySymbol);

    // Bootstrap first pair elegantly
    const displaySymbol = storeDisplaySymbol || (activeSymbols.length > 0 ? activeSymbols[0] : 'NO PAIR');

    useEffect(() => {
        if (!storeDisplaySymbol && activeSymbols.length > 0) {
            setDisplaySymbol(activeSymbols[0]);
        }
    }, [activeSymbols, storeDisplaySymbol, setDisplaySymbol]);

    // Radically enforce explicit REST hydration on pair load completely destroying initial delta sparsity
    useEffect(() => {
        if (displaySymbol !== 'NO PAIR') {
            hydrateOrderBook(displaySymbol);
        }
    }, [displaySymbol]);

    // Execute native structural mappings explicitly avoiding Canvas integer mapping failures!
    const { bidPath, askPath, bidColor, askColor, spreadPrice, minPriceStr, maxPriceStr, peakVol } = useMemo(() => {
        if (bids.length === 0 && asks.length === 0) {
            return { bidPath: '', askPath: '', bidColor: '', askColor: '', spreadPrice: 0, minPriceStr: 0, maxPriceStr: 0, peakVol: 0 };
        }

        // 1. Sort strictly mapping physical order sides correctly
        const sortedBidsDesc = [...bids].sort((a, b) => b.price - a.price); // Highest down
        const sortedAsksAsc = [...asks].sort((a, b) => a.price - b.price); // Lowest up

        // 2. Accumulate structural volumes correctly natively
        let accBid = 0;
        const bidPoints = sortedBidsDesc.map(b => ({ price: b.price, volume: accBid += b.quantity }));
        // Re-sort bids so X-axis renders correctly from Lowest Price to Highest Price
        bidPoints.sort((a, b) => a.price - b.price);

        let accAsk = 0;
        const askPoints = sortedAsksAsc.map(a => ({ price: a.price, volume: accAsk += a.quantity }));

        // 3. Define the ABSOLUTE Center lock to completely stop the violent zooming/jiggling!
        const currentSpread = bidPoints.length > 0 && askPoints.length > 0
            ? (bidPoints[bidPoints.length - 1].price + askPoints[0].price) / 2
            : (bidPoints.length > 0 ? bidPoints[bidPoints.length - 1].price : (askPoints.length > 0 ? askPoints[0].price : 0));

        // Lock the X-Axis bounds dynamically to exactly +/- 0.5% of the center spread.
        // This ensures the two blocks ALWAYS sit identically in the center and the aspect ratio NEVER moves!
        const depthRange = 0.005; // 0.5% visible spread window
        const fixedMinPrice = currentSpread * (1 - depthRange);
        const fixedMaxPrice = currentSpread * (1 + depthRange);

        // Sub-filter only the bounds inside our fixed visual window so a random whale order doesn't flatten the Y-axis
        const visibleBids = bidPoints.filter(p => p.price >= fixedMinPrice && p.price <= currentSpread);
        const visibleAsks = askPoints.filter(p => p.price <= fixedMaxPrice && p.price >= currentSpread);

        const maxVol = Math.max(
            visibleBids.length > 0 ? visibleBids[0].volume : 0, 
            visibleAsks.length > 0 ? visibleAsks[visibleAsks.length - 1].volume : 0,
            1 // prevent division by zero
        );

        const width = 1000;
        const height = 400;

        // X scaling natively secured inside the rigid Fixed Min/Max percentage block dynamically avoiding floats!
        const scaleX = (price: number) => {
            if (fixedMaxPrice === fixedMinPrice) return width / 2;
            return ((price - fixedMinPrice) / (fixedMaxPrice - fixedMinPrice)) * width;
        };

        const scaleY = (vol: number) => {
            return height - ((vol / maxVol) * height);
        };

        // Construct Emerald Bid SVG STEP Curve (Side-by-side block look)
        let bPath = '';
        if (visibleBids.length > 0) {
            bPath = `M ${scaleX(fixedMinPrice)},${height} `;
            bPath += `L ${scaleX(fixedMinPrice)},${scaleY(visibleBids[0].volume)} `; // Anchor left wall
            
             visibleBids.forEach((p, i) => {
                if (i === 0) {
                    bPath += `L ${scaleX(p.price)},${scaleY(p.volume)} `;
                } else {
                    const prev = visibleBids[i-1];
                    // Horizontal step forward, then vertical step down (creating exchange blocks)
                    bPath += `L ${scaleX(p.price)},${scaleY(prev.volume)} `;
                    bPath += `L ${scaleX(p.price)},${scaleY(p.volume)} `;
                }
            });

            // Anchor flawlessly to the center spread
            bPath += ` L ${scaleX(currentSpread)},${height} Z`;
        }

        // Construct Rose Ask SVG STEP Curve
        let aPath = '';
        if (visibleAsks.length > 0) {
            aPath = `M ${scaleX(currentSpread)},${height} `;
            
            visibleAsks.forEach((p, i) => {
                if (i === 0) {
                    aPath += `L ${scaleX(p.price)},${height} `;
                    aPath += `L ${scaleX(p.price)},${scaleY(p.volume)} `;
                } else {
                    const prev = visibleAsks[i-1];
                    // Horizontal step forward, then vertical step up (creating ascending blocks)
                    aPath += `L ${scaleX(p.price)},${scaleY(prev.volume)} `;
                    aPath += `L ${scaleX(p.price)},${scaleY(p.volume)} `;
                }
            });

            // Anchor flawlessly to the fixed right border
            aPath += ` L ${scaleX(fixedMaxPrice)},${scaleY(visibleAsks[visibleAsks.length - 1].volume)} `;
            aPath += ` L ${scaleX(fixedMaxPrice)},${height} Z`;
        }

        return {
            bidPath: bPath,
            askPath: aPath,
            bidColor: 'rgba(52, 211, 153, 0.4)', // emerald
            askColor: 'rgba(251, 113, 133, 0.4)', // rose
            spreadPrice: currentSpread,
            minPriceStr: fixedMinPrice,
            maxPriceStr: fixedMaxPrice,
            peakVol: maxVol
        };

    }, [bids, asks]);

    return (
        <div className="bg-slate-900/40 backdrop-blur-lg border border-slate-800/60 rounded-[24px] p-4 shadow-2xl flex flex-col relative overflow-hidden group hover:border-slate-700/60 transition-all duration-500 ease-out h-full min-h-0">
            {/* Ambient Blue Core */}
            <div className="absolute -top-[20%] -left-[10%] w-[60%] h-[60%] bg-blue-500/5 blur-[120px] rounded-full pointer-events-none group-hover:bg-blue-400/5 transition-colors duration-700"></div>
            
            {/* Header / Active Pair Overlay */}
            <div className="relative z-10 flex justify-between items-center mb-4">
                <div className="flex items-center gap-3">
                    <div className="p-2 bg-blue-500/20 rounded-lg border border-blue-500/30 shadow-[0_0_15px_rgba(59,130,246,0.2)]">
                        <svg className="w-5 h-5 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3" />
                        </svg>
                    </div>
                    <div className="flex flex-col">
                        <h3 className="text-lg font-bold text-slate-100 tracking-wider">Depth Chart</h3>
                        <div className="flex items-center space-x-2 mt-1">
                            <select 
                                value={displaySymbol}
                                onChange={(e) => setDisplaySymbol(e.target.value)}
                                className="bg-slate-800 border border-slate-700 text-xs font-mono text-slate-200 rounded-md py-1 px-2 focus:ring-blue-500 focus:border-blue-500 min-w-[100px]"
                            >
                                {activeSymbols.length === 0 && <option value="NO PAIR">NO PAIR</option>}
                                {activeSymbols.map(sym => (
                                    <option key={sym} value={sym}>{sym}</option>
                                ))}
                            </select>
                            <span className="text-xs font-mono text-slate-400 tracking-widest">| Spread: ${spreadPrice.toFixed(2)}</span>
                        </div>
                    </div>
                </div>
                
                {/* Visual Legend */}
                <div className="flex items-center space-x-4 text-[10px] font-mono select-none">
                    <span className="text-emerald-400 flex items-center"><span className="w-2 h-2 rounded-full bg-emerald-400 mr-2 shadow-[0_0_8px_currentColor]"></span>BIDS</span>
                    <span className="text-rose-400 flex items-center"><span className="w-2 h-2 rounded-full bg-rose-400 mr-2 shadow-[0_0_8px_currentColor]"></span>ASKS</span>
                </div>
            </div>

            {/* Native SVG Renderer bounds - Infinitely responsive without execution lockups! */}
            <div className="flex-1 w-full min-h-[400px] z-10 relative overflow-hidden flex flex-col">
                <div className="flex-1 relative w-full h-full flex items-end">
                    { bids.length === 0 && asks.length === 0 ? (
                        <div className="absolute inset-0 flex items-center justify-center text-slate-500 font-mono text-sm tracking-widest">AWAITING ORDERBOOK TICK...</div>
                    ) : (
                        <>
                            {/* Max Volume Indicator Y-Axis */}
                            <div className="absolute top-2 left-2 text-[10px] font-mono text-slate-500/80">
                                PEAK VOL: {peakVol.toFixed(2)}
                            </div>
                            
                            <svg viewBox="0 0 1000 400" preserveAspectRatio="none" className="w-full h-full drop-shadow-[0_0_20px_rgba(0,0,0,0.5)]">
                                {/* Emerald Bid Mountain */}
                                {bidPath && (
                                    <path d={bidPath} fill={bidColor} stroke="#34d399" strokeWidth="2" vectorEffect="non-scaling-stroke" />
                                )}
                                {/* Rose Ask Mountain */}
                                {askPath && (
                                    <path d={askPath} fill={askColor} stroke="#fb7185" strokeWidth="2" vectorEffect="non-scaling-stroke" />
                                )}
                                {/* Ghost Center Line marking spread zero point visually */}
                                <line x1="50%" y1="0" x2="50%" y2="100%" stroke="rgba(255,255,255,0.05)" strokeWidth="1" strokeDasharray="5,5" />
                            </svg>
                        </>
                    )}
                </div>
                
                {/* Fixed X-Axis Graph Keys */}
                <div className="w-full flex justify-between px-1 pt-3 mt-1 border-t border-slate-800/40 text-[10px] font-mono text-slate-500 select-none">
                    <span>${minPriceStr > 0 ? (minPriceStr < 10 ? minPriceStr.toFixed(4) : minPriceStr.toFixed(2)) : '0.00'}</span>
                    <span className="text-slate-400">SPREAD ZERO</span>
                    <span>${maxPriceStr > 0 ? (maxPriceStr < 10 ? maxPriceStr.toFixed(4) : maxPriceStr.toFixed(2)) : '0.00'}</span>
                </div>
            </div>
        </div>
    );
}
