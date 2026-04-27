import React, { useEffect, useRef } from 'react';
import { useTradingStore } from '../../stores/useTradingStore';

export default function AlphaFeed() {
    const alphaFeed = useTradingStore((state) => state.alphaFeed);
    const containerRef = useRef<HTMLDivElement>(null);

    // Auto-scroll to top when new signals arrive (since we prepend to array)
    useEffect(() => {
        if (containerRef.current) {
            containerRef.current.scrollTop = 0;
        }
    }, [alphaFeed]);

    return (
        <div className="bg-slate-900/40 backdrop-blur-lg border border-slate-800/60 rounded-[24px] p-5 shadow-2xl flex flex-col relative overflow-hidden group hover:border-slate-700/60 transition-all duration-500 ease-out h-full min-h-0">
            {/* Ambient Fuchsia Core */}
            <div className="absolute -top-[30%] -right-[10%] w-[70%] h-[70%] bg-fuchsia-500/5 blur-[120px] rounded-full pointer-events-none group-hover:bg-fuchsia-400/5 transition-colors duration-700 z-0"></div>
            
            <div className="flex items-center justify-between mb-4 z-10 shrink-0">
                <span className="text-slate-500 font-sans text-xs font-bold tracking-[0.2em] uppercase select-none flex items-center">
                    <span className="w-1.5 h-1.5 rounded-full bg-fuchsia-500 mr-2 animate-pulse shadow-[0_0_8px_rgba(217,70,239,0.5)]"></span>
                    Intelligence Stream
                </span>
                <span className="text-[10px] uppercase font-mono tracking-wider text-slate-600 bg-slate-800/50 px-2 py-1 rounded">
                    {alphaFeed.length} SIGNALS
                </span>
            </div>

            <div 
                ref={containerRef}
                className="flex-1 overflow-y-auto z-10 pr-2 space-y-2 pb-4 scroll-smooth scrollbar-thin scrollbar-thumb-slate-700 scrollbar-track-transparent"
            >
                {alphaFeed.length === 0 ? (
                    <div className="flex flex-col items-center justify-center h-full text-slate-600 font-mono text-xs uppercase tracking-widest opacity-50">
                        <div className="w-8 h-8 border border-slate-700 border-t-slate-500 rounded-full animate-spin mb-4"></div>
                        Awaiting Ingestion...
                    </div>
                ) : (
                    alphaFeed.map((signal) => {
                        // 0.85 / -0.85 are the strict default thresholds for evaluation execution
                        const isBullish = signal.score >= 0.85;
                        const isBearish = signal.score <= -0.85;
                        
                        let scoreColorClass = 'text-slate-400';
                        if (isBullish) scoreColorClass = 'text-emerald-400';
                        if (isBearish) scoreColorClass = 'text-rose-400';

                        return (
                            <div 
                                key={signal.id} 
                                className="w-full bg-slate-950/60 rounded-xl p-3 border border-slate-800/50 flex flex-col gap-2 hover:bg-slate-900 transition-colors group/signal"
                            >
                                <div className="flex justify-between items-center w-full">
                                    <div className="flex items-center gap-2">
                                        {/* Status Dot */}
                                        <div className="w-1.5 h-1.5 rounded-full bg-slate-500 shadow-[0_0_5px_rgba(100,116,139,0.5)]"></div>
                                        <span className="text-slate-300 font-bold font-mono text-sm tracking-wider">{signal.symbol}</span>
                                    </div>
                                    <div className={`font-mono text-xs font-bold tracking-widest ${scoreColorClass}`}>
                                        SC: {signal.score > 0 ? '+' : ''}{signal.score.toFixed(3)}
                                    </div>
                                </div>
                                
                                {signal.strategy && (
                                    <div className="w-fit bg-slate-900 border border-slate-700/50 rounded-sm px-1.5 py-0.5 text-[9px] font-mono tracking-widest text-slate-400 uppercase mt-1">
                                        {signal.strategy.replace('Strategy', '')}
                                    </div>
                                )}
                                
                                {signal.status === 'REJECTED' && (
                                    <div className="bg-rose-950/20 border border-slate-800/80 rounded block px-2.5 py-1.5 text-[10px] font-sans tracking-wide text-slate-400 leading-relaxed shadow-inner">
                                        <span className="text-slate-500 font-bold uppercase mr-1">Blocked:</span> 
                                        {signal.reason}
                                    </div>
                                )}
                            </div>
                        );
                    })
                )}
            </div>
        </div>
    );
}
