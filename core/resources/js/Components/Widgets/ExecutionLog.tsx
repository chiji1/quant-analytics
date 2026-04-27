import React from 'react';
import { useTradingStore } from '../../stores/useTradingStore';

export default function ExecutionLog() {
    const activeTrades = useTradingStore((state) => state.activeTrades);

    // Filter height to structural alignment (max 10 rows safely mapped to viewport logic bounds)
    const displayTrades = activeTrades.slice(0, 10);

    const formatTime = (ts: number) => {
        const d = new Date(ts);
        const hh = d.getHours().toString().padStart(2, '0');
        const mm = d.getMinutes().toString().padStart(2, '0');
        const ss = d.getSeconds().toString().padStart(2, '0');
        return `${hh}:${mm}:${ss}`;
    };

    return (
        <div className="bg-slate-900/40 backdrop-blur-lg border border-slate-800/60 rounded-[24px] p-4 shadow-2xl flex flex-col relative overflow-hidden group hover:border-slate-700/60 transition-all duration-500 ease-out h-full min-h-0">
            {/* Ambient Emerald Core */}
            <div className="absolute bottom-0 w-[100%] h-[50%] bg-emerald-500/5 blur-[120px] rounded-full pointer-events-none group-hover:bg-emerald-400/5 transition-colors duration-700"></div>
            
            <span className="text-slate-500 font-sans text-xs font-bold tracking-[0.2em] relative uppercase select-none mb-6 z-10 block flex-shrink-0">
                Execution Log Stream
            </span>

            <div className="w-full overflow-x-auto overflow-y-auto z-10 relative flex-1 custom-scrollbar min-h-0">
                <table className="w-full text-left border-collapse table-fixed">
                    <thead>
                        <tr className="border-b border-slate-800/60">
                            <th className="pb-3 text-[10px] uppercase font-sans tracking-[0.2em] text-slate-500 font-medium whitespace-nowrap w-[20%]">Time</th>
                            <th className="pb-3 text-[10px] uppercase font-sans tracking-[0.2em] text-slate-500 font-medium whitespace-nowrap w-[25%]">Pair</th>
                            <th className="pb-3 text-[10px] uppercase font-sans tracking-[0.2em] text-slate-500 font-medium whitespace-nowrap w-[15%]">Side</th>
                            <th className="pb-3 text-[10px] uppercase font-sans tracking-[0.2em] text-slate-500 font-medium text-right whitespace-nowrap w-[20%]">Size</th>
                            <th className="pb-3 text-[10px] uppercase font-sans tracking-[0.2em] text-slate-500 font-medium text-right whitespace-nowrap w-[20%]">Price</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-800/30">
                        {displayTrades.length === 0 ? (
                            <tr>
                                <td colSpan={5} className="py-8 text-center text-xs text-slate-600/70 font-mono italic">
                                    [ Awaiting Execution Signals ]
                                </td>
                            </tr>
                        ) : (
                            displayTrades.map((trade) => (
                                <tr key={trade.id} className="hover:bg-slate-800/30 transition-colors group/row">
                                    <td className="py-3 text-xs text-slate-400 font-mono whitespace-nowrap group-hover/row:text-slate-300">
                                        {formatTime(trade.timestamp)}
                                    </td>
                                    <td className="py-3 text-xs text-slate-200 font-mono font-medium whitespace-nowrap">
                                        <div className="flex flex-col">
                                            <span>{trade.symbol}</span>
                                            {trade.strategy && (
                                                <span className="text-[9px] text-slate-500 font-sans tracking-widest uppercase mt-0.5">
                                                    {trade.strategy.replace('Strategy', '')}
                                                </span>
                                            )}
                                        </div>
                                    </td>
                                    <td className={`py-3 text-[10px] font-mono font-bold tracking-widest whitespace-nowrap drop-shadow-sm ${trade.side === 'BUY' ? 'text-emerald-400' : 'text-rose-400'}`}>
                                        {trade.side}
                                    </td>
                                    <td className="py-3 text-xs text-slate-300 font-mono text-right whitespace-nowrap">
                                        {trade.quantity.toLocaleString(undefined, { maximumFractionDigits: 4 })}
                                    </td>
                                    <td className="py-3 text-xs text-slate-200 font-mono text-right whitespace-nowrap group-hover/row:text-white">
                                        {trade.price.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 6 })}
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>
            
            {/* Visual Glass Edge Bottom Fade */}
            <div className="absolute bottom-0 left-0 right-0 h-10 bg-gradient-to-t from-slate-900/80 to-transparent pointer-events-none rounded-b-[28px] z-20"></div>
        </div>
    );
}
