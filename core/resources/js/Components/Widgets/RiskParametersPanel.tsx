import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { useTradingStore, RiskParameters } from '../../stores/useTradingStore';

export default function RiskParametersPanel() {
    const riskParameters = useTradingStore(state => state.riskParameters);
    const fetchRiskParameters = useTradingStore(state => state.fetchRiskParameters);
    const setRiskParameters = useTradingStore(state => state.setRiskParameters);
    const [isSaving, setIsSaving] = useState(false);
    const [hasSaved, setHasSaved] = useState(false);

    // Local form state so we only update backend on explicit save
    const [formData, setFormData] = useState<RiskParameters>(riskParameters);
    const [rawSymbols, setRawSymbols] = useState<string>('');

    // Initialize state
    useEffect(() => {
        fetchRiskParameters();
    }, []);

    // Sync formData when riskParameters change from server
    useEffect(() => {
        setFormData(riskParameters);
        if (riskParameters.allowed_symbols) {
            setRawSymbols(riskParameters.allowed_symbols.join(', '));
        }
    }, [riskParameters]);

    const handleChange = (field: keyof RiskParameters, value: any) => {
        setFormData(prev => ({ ...prev, [field]: value }));
    };

    const handleSymbolChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        // Allow the user to type anything (including trailing commas/spaces)
        const value = e.target.value.toUpperCase();
        setRawSymbols(value);
        
        // Quietly sync the parsed array into the form data for the DB payload
        const symbols = value.split(',').map(s => s.trim()).filter(Boolean);
        handleChange('allowed_symbols', symbols);
    };

    const handleSave = async () => {
        setIsSaving(true);
        try {
            const response = await axios.post('/api/risk-parameters', formData);

            if (response.status === 200 && response.data.success) {
                setRiskParameters(response.data.data);
                
                // Activate success visual feedback
                setHasSaved(true);
                setTimeout(() => setHasSaved(false), 2000);
            } else {
                console.error("Configuration failed to deploy.");
            }
        } catch (error: any) {
            console.error("Payload rejection (likely validation or network timeout):", error);
            const msg = error.response?.data?.message || error.message;
            alert("Execution Denied: " + msg);
        } finally {
            setIsSaving(false);
        }
    };

    const handleStrategyToggle = (strategyName: string) => {
        const current = formData.active_strategies || [];
        if (current.includes(strategyName)) {
            handleChange('active_strategies', current.filter(s => s !== strategyName));
        } else {
            handleChange('active_strategies', [...current, strategyName]);
        }
    };

    const StrategyCheckbox = ({ name, title, description }: { name: string, title: string, description: string }) => {
        const isActive = (formData.active_strategies || []).includes(name);
        return (
            <div 
                onClick={() => handleStrategyToggle(name)}
                className={`relative group/stratcb flex flex-col p-3 rounded-xl border cursor-pointer transition-all duration-300 ${isActive ? 'bg-indigo-900/30 border-indigo-500 shadow-[0_0_15px_rgba(99,102,241,0.2)]' : 'bg-slate-900/40 border-slate-700/50 hover:bg-slate-800'}`}
            >
                <div className="flex items-center justify-between mb-1">
                    <span className={`text-[11px] font-bold tracking-wider ${isActive ? 'text-indigo-300' : 'text-slate-400'}`}>{title}</span>
                    <div className={`w-4 h-4 rounded mt-0.5 border flex items-center justify-center transition-all ${isActive ? 'bg-indigo-500 border-indigo-400' : 'border-slate-500'}`}>
                        {isActive && <span className="text-white text-[10px] leading-none">✓</span>}
                    </div>
                </div>
                <p className="text-[10px] text-slate-500 leading-tight">Hover to learn how this works...</p>

                {/* Pedagogy Tooltip */}
                <div className="absolute top-full left-[50%] -translate-x-[50%] mt-3 w-64 p-3 bg-slate-800 border ${isActive ? 'border-indigo-500/50' : 'border-slate-600'} rounded-lg text-[10.5px] text-slate-300 tracking-wider shadow-[0_10px_40px_rgba(0,0,0,0.8)] opacity-0 group-hover/stratcb:opacity-100 transition-opacity pointer-events-none z-[9999]">
                    <span className={`font-bold block mb-1 ${isActive ? 'text-indigo-400' : 'text-slate-100'}`}>{title}</span>
                    {description}
                </div>
            </div>
        );
    };

    return (
        <div className="relative group w-full z-50">
            <div className="absolute inset-0 bg-indigo-500/10 blur-[120px] rounded-full pointer-events-none transition-opacity duration-1000 group-hover:opacity-100 opacity-40"></div>
            
            <div className="relative border border-slate-800/60 bg-slate-900/40 backdrop-blur-lg rounded-[24px] p-5 shadow-2xl flex flex-col gap-6 w-full z-10">
                <div className="flex flex-col md:flex-row items-center justify-between gap-6 w-full">
                    {/* Header Section */}
                    <div className="flex flex-col items-start min-w-[200px]">
                        <h2 className="text-sm font-sans tracking-[0.2em] uppercase text-slate-400 font-semibold mb-2 flex items-center">
                            <span className="w-1.5 h-1.5 rounded-full bg-indigo-500 mr-3 animate-pulse shadow-[0_0_8px_rgba(99,102,241,0.8)]"></span>
                            Parameter Control
                        </h2>
                        
                        {/* DEPLOY BUTTON & TOOLTIP */}
                        <div className="relative flex items-center w-full mt-1 group/deploy">
                            <button 
                                onClick={handleSave} 
                                disabled={isSaving || hasSaved}
                                className={`w-full text-[11px] font-sans font-bold tracking-widest uppercase px-4 py-2.5 rounded-lg border transition-all flex items-center justify-center relative z-10 ${
                                    isSaving 
                                        ? 'bg-indigo-500/20 text-indigo-400 border-indigo-500/40 cursor-wait' 
                                        : hasSaved
                                            ? 'bg-emerald-500 text-white border-emerald-400 shadow-[0_0_20px_rgba(16,185,129,0.4)] cursor-default'
                                            : 'bg-indigo-500/20 hover:bg-indigo-500/40 text-indigo-300 border-indigo-500/40 shadow-[0_0_15px_rgba(99,102,241,0.2)] cursor-pointer'
                                }`}
                            >
                                {isSaving ? 'DEPLOYING...' : hasSaved ? 'SYNCED ✓' : 'DEPLOY TO NETWORK'}
                            </button>
                            
                            {/* Tooltip */}
                            <div className="absolute top-full left-0 mt-3 w-64 p-3 bg-slate-800 border border-slate-600 rounded-lg text-[10.5px] text-slate-300 tracking-wider shadow-[0_10px_40px_rgba(0,0,0,0.8)] opacity-0 group-hover/deploy:opacity-100 transition-opacity pointer-events-none z-[9999]">
                                Pushes config to Redis instantly. Python ingestors will dynamically reflect updates on their next CPU tick—no restarts required.
                            </div>
                        </div>
                    </div>

                    {/* Form Fields - Horizontal Grid */}
                    <div className="flex-1 grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-x-4 gap-y-6 w-full mt-2 md:mt-0">
                        {/* Base Allocation */}
                        <div className="flex flex-col justify-end relative group/base cursor-help w-full">
                            <label className="block text-slate-500 tracking-wider mb-1 text-[10px] uppercase">Base Limit (USDT)</label>
                            <input 
                                type="number" 
                                className="w-full bg-slate-950/50 border border-slate-700/50 rounded-xl px-3 py-2 text-slate-300 font-mono text-xs focus:ring-1 focus:ring-slate-500 outline-none transition-colors hover:bg-slate-800/50 relative z-10 cursor-text"
                                value={formData.base_allocation_usdt ?? ''}
                                onChange={(e) => handleChange('base_allocation_usdt', e.target.value === '' ? '' : parseFloat(e.target.value))}
                            />
                            {/* Tooltip */}
                            <div className="absolute top-full left-0 mt-3 w-56 p-3 bg-slate-800 border border-slate-600 rounded-lg text-[10.5px] text-slate-300 tracking-wider shadow-[0_10px_40px_rgba(0,0,0,0.8)] opacity-0 group-hover/base:opacity-100 transition-opacity pointer-events-none z-[9999]">
                                <span className="text-slate-100 font-bold block mb-1">STAKING BASELINE</span>
                                The standard non-leveraged raw USDT inherently assigned per bracket deployment prior to mathematical conviction multipliers.
                            </div>
                        </div>

                        {/* Max Allocation */}
                        <div className="flex flex-col justify-end relative group/maxalloc cursor-help w-full">
                            <label className="block text-yellow-500/80 tracking-wider mb-1 text-[10px] uppercase font-bold">Max Ceiling (USDT)</label>
                            <input 
                                type="number" 
                                className="w-full bg-yellow-950/20 border border-yellow-900/50 rounded-xl px-3 py-2 text-yellow-400 font-mono text-xs focus:ring-1 focus:ring-yellow-500 outline-none transition-colors hover:bg-yellow-900/40 relative z-10 cursor-text"
                                value={formData.max_trade_allocation_usdt ?? ''}
                                onChange={(e) => handleChange('max_trade_allocation_usdt', e.target.value === '' ? '' : parseFloat(e.target.value))}
                            />
                            {/* Tooltip */}
                            <div className="absolute top-full left-0 mt-3 w-64 p-3 bg-slate-800 border border-yellow-900/50 rounded-lg text-[10.5px] text-slate-300 tracking-wider shadow-[0_10px_40px_rgba(0,0,0,0.8)] opacity-0 group-hover/maxalloc:opacity-100 transition-opacity pointer-events-none z-[9999]">
                                <span className="text-yellow-400 font-bold block mb-1">COLLATERAL CEILING</span>
                                The absolute mathematically terminal ceiling. Leverage modifiers (10x-20x) are strictly clamped from exceeding this absolute structural value identically.
                            </div>
                        </div>

                        {/* Allowed Symbols */}
                        <div className="flex flex-col justify-end relative group/whitelist cursor-help lg:col-span-2 xl:col-span-1">
                            <label className="block text-slate-500 tracking-wider mb-1 text-[10px] uppercase">Asset Whitelist</label>
                            <input 
                                type="text" 
                                className="w-full bg-slate-950/50 border border-slate-700/50 rounded-xl px-3 py-2 text-slate-300 font-mono text-xs focus:ring-1 focus:ring-indigo-500 outline-none transition-colors hover:bg-slate-800/50 relative z-10 cursor-text"
                                value={rawSymbols}
                                onChange={handleSymbolChange}
                                placeholder="BTCUSDT, ETHUSDT"
                            />
                            {/* Tooltip */}
                            <div className="absolute top-full left-0 mt-3 w-64 p-3 bg-slate-800 border border-slate-600 rounded-lg text-[10.5px] text-slate-300 tracking-wider shadow-[0_10px_40px_rgba(0,0,0,0.8)] opacity-0 group-hover/whitelist:opacity-100 transition-opacity pointer-events-none z-[9999]">
                                <span className="text-slate-100 font-bold block mb-1">SECURITY BARRIER</span>
                                A strict array of Binance pairs. The Python stream physical drops hallucinated LLM tickers failing to match this matrix inherently.
                            </div>
                        </div>

                        {/* Dynamic Leverage Toggle */}
                        <div className="flex flex-col justify-end relative group/dynamic items-center border border-slate-700/50 rounded-xl py-1.5 w-full cursor-help h-[54px] xl:col-span-1">
                            <label className="text-[9px] text-slate-400 tracking-wider uppercase mb-1">Auto Leverage</label>
                            <button 
                                onClick={() => handleChange('dynamic_leverage_enabled', !formData.dynamic_leverage_enabled)}
                                className={`w-12 h-5 rounded-full relative transition-all duration-300 shadow-[inset_0_2px_4px_rgba(0,0,0,0.4)] z-10 ${formData.dynamic_leverage_enabled ? 'bg-indigo-500' : 'bg-slate-700 hover:bg-slate-600'}`}
                            >
                                <span className={`absolute top-0.5 left-1 w-4 h-4 rounded-full bg-white transition-transform duration-300 shadow-sm ${formData.dynamic_leverage_enabled ? 'transform translate-x-6' : ''}`}></span>
                            </button>
                            {/* Tooltip */}
                            <div className="absolute top-full left-[50%] -translate-x-[50%] mt-3 w-60 p-3 bg-slate-800 border border-slate-600 rounded-lg text-[10.5px] text-slate-300 tracking-wider shadow-[0_10px_40px_rgba(0,0,0,0.8)] opacity-0 group-hover/dynamic:opacity-100 transition-opacity pointer-events-none z-[9999]">
                                <span className="text-indigo-400 font-bold block mb-1">ALGORITHMIC MARGIN</span>
                                Hooks directly into Binance `POST /fapi/v1/leverage`. Generates a sliding 2x-10x trajectory natively based solely upon AI sentiment extremities. 
                            </div>
                        </div>
                        
                        {/* BUY MIN */}
                        <div className="flex flex-col justify-end relative group/buymin cursor-help col-span-1 border-t border-slate-800 pt-4 mt-2">
                            <label className="block text-emerald-600/80 tracking-wider mb-1 text-[10px] uppercase font-bold">News Min (BUY)</label>
                            <input 
                                type="number" 
                                step="0.01"
                                className="w-full bg-emerald-950/20 border border-emerald-900/50 rounded-xl px-3 py-2 text-emerald-400 font-mono text-xs focus:ring-1 focus:ring-emerald-500 outline-none transition-colors hover:bg-emerald-900/40 relative z-10 cursor-text"
                                value={formData.news_sentiment_buy_threshold ?? ''}
                                onChange={(e) => handleChange('news_sentiment_buy_threshold', e.target.value === '' ? '' : parseFloat(e.target.value))}
                            />
                            <div className="absolute top-full left-0 mt-3 w-56 p-3 bg-slate-800 border border-emerald-900/50 rounded-lg text-[10.5px] text-slate-300 tracking-wider shadow-[0_10px_40px_rgba(0,0,0,0.8)] opacity-0 group-hover/buymin:opacity-100 transition-opacity pointer-events-none z-[9999]">
                                <span className="text-emerald-400 font-bold block mb-1">BULLISH LIMIT</span>
                                The parsed AI news sentiment must equal or exceed this threshold before submitting a LONG pipeline request natively.
                            </div>
                        </div>
                        
                        {/* SELL MAX */}
                        <div className="flex flex-col justify-end relative group/sellmax cursor-help col-span-1 border-t border-slate-800 pt-4 mt-2">
                            <label className="block text-rose-600/80 tracking-wider mb-1 text-[10px] uppercase font-bold">News Max (SELL)</label>
                            <input 
                                type="number" 
                                step="0.01"
                                className="w-full bg-rose-950/20 border border-rose-900/50 rounded-xl px-3 py-2 text-rose-400 font-mono text-xs focus:ring-1 focus:ring-rose-500 outline-none transition-colors hover:bg-rose-900/40 relative z-10 cursor-text"
                                value={formData.news_sentiment_sell_threshold ?? ''}
                                onChange={(e) => handleChange('news_sentiment_sell_threshold', e.target.value === '' ? '' : parseFloat(e.target.value))}
                            />
                            <div className="absolute top-full left-0 mt-3 w-56 p-3 bg-slate-800 border border-rose-900/50 rounded-lg text-[10.5px] text-slate-300 tracking-wider shadow-[0_10px_40px_rgba(0,0,0,0.8)] opacity-0 group-hover/sellmax:opacity-100 transition-opacity pointer-events-none z-[9999]">
                                <span className="text-rose-400 font-bold block mb-1">BEARISH LIMIT</span>
                                If the parsed AI sentiment evaluates strictly below this threshold, a SHORT market bias sequence is generated natively.
                            </div>
                        </div>

                    </div>
                    
                    {/* Kill Switch Area */}
                    <div className="relative flex flex-col items-center justify-center pl-4 border-l border-slate-800/60 group/kill mt-2 md:mt-0">
                        <label className="text-slate-500 tracking-wider uppercase font-semibold text-[10px] mb-2 text-center cursor-help">
                            Global Kill Switch
                        </label>
                        <button 
                            onClick={() => handleChange('global_kill_switch', !formData.global_kill_switch)}
                            className={`w-14 h-7 rounded-full relative transition-all duration-300 shadow-[inset_0_2px_4px_rgba(0,0,0,0.4)] z-10 ${formData.global_kill_switch ? 'bg-rose-500 shadow-[0_0_20px_rgba(244,63,94,0.6)]' : 'bg-slate-700 hover:bg-slate-600'}`}
                        >
                            <span className={`absolute top-1 left-1 w-5 h-5 rounded-full bg-white transition-transform duration-300 shadow-sm ${formData.global_kill_switch ? 'transform translate-x-7' : ''}`}></span>
                        </button>
                        
                        {/* Tooltip */}
                        <div className="absolute top-full right-0 mt-3 w-64 p-3 bg-slate-800 border border-rose-500/50 rounded-lg text-[10.5px] text-slate-300 tracking-wider shadow-[0_10px_40px_rgba(0,0,0,0.8)] opacity-0 group-hover/kill:opacity-100 transition-opacity pointer-events-none z-[9999]">
                            <span className="text-rose-400 font-bold block mb-1">WARNING: PHYSICAL OVERRIDE</span>
                            Activating the kill switch suspends all limits at the PHP Core level, severing Binance network bindings instantly while leaving the AI intelligence stream running cleanly natively.
                        </div>
                    </div>
                </div>

                {/* Multiselect Checkbox Layer */}
                <div className="w-full border-t border-slate-800/60 pt-5 mt-2">
                    <label className="block text-indigo-400/80 tracking-widest mb-3 text-[10px] uppercase font-bold">Algorithmic Strategies (Multi-Select Architecture)</label>
                    <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                        <StrategyCheckbox 
                            name="AlphaNewsChaserStrategy" 
                            title="Alpha News Chaser" 
                            description="Reads streaming cryptoverse sentiment via Ollama LLMs. Dynamically executes tier-1 VWAP depth absorption based on dynamic cross margin leverage (x2-x10) explicitly overriding standard risk blocks during confirmed +0.85 momentum bursts."
                        />
                        <StrategyCheckbox 
                            name="CascadingPanicShortStrategy" 
                            title="Cascading Panic Short" 
                            description="Exploits massive fake $3M+ liquidation wicks. Uses 1m K-line Retracement evaluators to verify the wick is an unsustainable air-pocket, then safely short-squeezes it dynamically absorbing VWAP liquidity until the reversion limits hit."
                        />
                        <StrategyCheckbox 
                            name="LiquiditySweepStrategy" 
                            title="Liquidity Sweep" 
                            description="Calculates deep negative basis liquidations exceeding $5M. Engages only when structural precision filters confirm a safe order-book foundation underneath before deploying bracket allocations mathematically trapping the flash-crash."
                        />
                        <StrategyCheckbox 
                            name="OrderBookImbalanceStrategy" 
                            title="Order Book Imbalance" 
                            description="Evaluates the raw WebSocket depth caches up to 50 levels down. Only triggers when DOM mass physically exceeds a 7:1 volume anomaly, automatically passing the entire raw USDT stack through the centralized intelligent Bracket VWAP execution bus."
                        />
                        <StrategyCheckbox 
                            name="StatisticalArbitrageStrategy" 
                            title="Statistical Arbitrage" 
                            description="Quantifies the relative correlation delta between BTC/ETH limits. Executes simultaneous dual leg brackets resolving exact basis convergence limits natively protecting against isolated asset deviations."
                        />
                        <StrategyCheckbox 
                            name="ToxicFlowAirPocketStrategy" 
                            title="Toxic Flow Air Pocket" 
                            description="Detects sudden bid cancellations. Scans the limit array for a complete lack of absorption buffers and rides the negative deviation natively using isolated dynamic margins."
                        />
                    </div>
                </div>

            </div>
        </div>
    );
}
