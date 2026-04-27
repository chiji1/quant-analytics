import React from 'react';
import { Head } from '@inertiajs/react';
import { useEngineEcho } from '../hooks/useEngineEcho';
import { useTradingStore } from '../stores/useTradingStore';
import OrderBookChart from '../Components/Widgets/OrderBookChart';
import AlphaFeed from '../Components/Widgets/AlphaFeed';
import ExecutionLog from '../Components/Widgets/ExecutionLog';
import RiskParametersPanel from '../Components/Widgets/RiskParametersPanel';

export default function Dashboard() {
    // Wire the WebSocket event stream directly into global Zustand memory unconditionally
    useEngineEcho();

    const fetchHistoricalTrades = useTradingStore((state) => state.fetchHistoricalTrades);

    React.useEffect(() => {
        // Hydrate Execution Ledger robustly from MySQL persistence mapping synchronously spanning initial layout mount sequence
        fetchHistoricalTrades();
    }, [fetchHistoricalTrades]);

    // Isolate single reactivity bind to engine status for the UI header mapping
    const systemStatus = useTradingStore((state) => state.systemStatus);

    return (
        <>
            <Head title="Control Room" />

            <div className="min-h-screen w-full bg-slate-950 text-slate-200 font-mono tracking-tight p-4 md:p-6 transition-colors duration-300 relative pb-20">
                
                {/* Global Background Layer for Overflow Protection */}
                <div className="fixed inset-0 bg-slate-950 z-[-1] pointer-events-none"></div>

                {/* Restored Header Navbar */}
                <header className="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 pb-4 border-b border-slate-800/80 gap-4">
                    <div>
                        <h1 className="text-xl font-bold tracking-widest text-slate-100 shadow-sm uppercase flex items-center space-x-2">
                            <span className="text-blue-500 mr-2">█</span>
                            Quant Engine
                        </h1>
                        <p className="text-[10px] text-slate-500 mt-2 uppercase tracking-[0.2em] font-medium">
                            Phase 4 Control Room Architecture
                        </p>
                    </div>
                </header>

                {/* Grid Layout Canvas */}
                <div className="flex flex-col gap-6 max-w-[1920px] mx-auto">
                    
                    {/* Top Level Action Row: Engine Parameters */}
                    <div className="w-full">
                        <RiskParametersPanel />
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 lg:h-[calc(100vh-220px)] min-h-[600px]">
                        
                        {/* Primary Widget Focus - Spans 2 */}
                        <div className="lg:col-span-2 h-full min-h-0">
                            {/* Widget: Order Book Imbalance Canvas */}
                            <OrderBookChart />
                        </div>

                        {/* Secondary Metrics - Spans 1 */}
                        <div className="flex flex-col gap-6 h-full min-h-0">
                            
                            {/* Widget: Alpha Feed (AI) */}
                            <div className="flex-1 min-h-0">
                                <AlphaFeed />
                            </div>

                            {/* Widget: Execution Log */}
                            <div className="flex-[1.2] min-h-0">
                                <ExecutionLog />
                            </div>

                        </div>
                    </div>
                </div>

            </div>
        </>
    );
}
