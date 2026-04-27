import { create } from 'zustand';

// Define strict typing for market buffers
export interface OrderBookLevel {
    price: number;
    quantity: number;
}

export interface TradeExecution {
    id: string;
    symbol: string;
    side: 'BUY' | 'SELL';
    quantity: number;
    price: number;
    timestamp: number;
    strategy?: string;
}

export interface RiskParameters {
    allowed_symbols: string[];
    base_allocation_usdt: number;
    max_trade_allocation_usdt: number;
    news_sentiment_buy_threshold: number;
    news_sentiment_sell_threshold: number;
    global_kill_switch: boolean;
    dynamic_leverage_enabled: boolean;
    active_strategies: string[];
}

export interface AlphaSignal {
    id: string;
    symbol: string;
    score: number;
    reason: string;
    timestamp: number;
    status: 'REJECTED' | 'EVALUATING';
    strategy?: string;
}

export interface TradingStoreState {
    orderBook: { bids: OrderBookLevel[]; asks: OrderBookLevel[] };
    aiSentiment: number;
    activeTrades: TradeExecution[];
    alphaFeed: AlphaSignal[];
    systemStatus: string;
    riskParameters: RiskParameters;
    
    // Actions mapping explicitly to echo broadcasts
    setOrderBook: (bids: OrderBookLevel[], asks: OrderBookLevel[]) => void;
    updateSentiment: (sentiment: number) => void;
    addTrade: (trade: TradeExecution) => void;
    addAlphaSignal: (signal: AlphaSignal) => void;
    setSystemStatus: (status: string) => void;
    setRiskParameters: (params: RiskParameters) => void;
    fetchRiskParameters: () => Promise<void>;
    hydrateOrderBook: (symbol: string) => Promise<void>;

    displaySymbol: string | null;
    setDisplaySymbol: (symbol: string) => void;
}

// Client-side execution memory pool
export const useTradingStore = create<TradingStoreState>((set) => ({
    orderBook: { bids: [], asks: [] },
    aiSentiment: 0.0,
    activeTrades: [],
    alphaFeed: [],
    systemStatus: 'INITIALIZING',
    riskParameters: {
        allowed_symbols: [],
        base_allocation_usdt: 10000,
        max_trade_allocation_usdt: 100000,
        news_sentiment_buy_threshold: 0.85,
        news_sentiment_sell_threshold: -0.85,
        global_kill_switch: false,
        dynamic_leverage_enabled: true,
        active_strategies: ['AlphaNewsChaserStrategy']
    },
    
    displaySymbol: null,
    setDisplaySymbol: (symbol) => set({ displaySymbol: symbol }),

    setOrderBook: (bids, asks) => set({ orderBook: { bids, asks } }),
    updateSentiment: (sentiment) => set({ aiSentiment: sentiment }),
    
    // Limits persistent memory overhead to trailing 50 broadcasted tickets to avoid browser DOM crashing
    addTrade: (trade) => set((state) => {
        // Prevent race condition duplication if WebSocket broadcasts during history fetch sequence
        const existingIds = new Set(state.activeTrades.map(t => t.id));
        if (existingIds.has(trade.id)) return state;
        return { activeTrades: [trade, ...state.activeTrades].slice(0, 50) };
    }),

    setTrades: (trades) => set({ activeTrades: trades }),
    
    addAlphaSignal: (signal) => set((state) => ({
        alphaFeed: [signal, ...state.alphaFeed].slice(0, 50)
    })),
    setSystemStatus: (status) => set({ systemStatus: status }),
    setRiskParameters: (params) => set({ riskParameters: params }),
    
    // Core Delta Snapshot Hydration - Injects structural depth foundation prior to WebSocket Delta mapping
    hydrateOrderBook: async (symbol: string) => {
        try {
            const res = await fetch(`https://fapi.binance.com/fapi/v1/depth?symbol=${symbol.toUpperCase()}&limit=50`);
            const json = await res.json();
            if (json.bids && json.asks) {
                const bids = json.bids.map((b: any) => ({ price: parseFloat(b[0]), quantity: parseFloat(b[1]) }));
                const asks = json.asks.map((a: any) => ({ price: parseFloat(a[0]), quantity: parseFloat(a[1]) }));
                set({ orderBook: { bids, asks } });
            }
        } catch (e) {
            console.error("Hydration Failure:", e);
        }
    },

    fetchRiskParameters: async () => {
        try {
            const res = await fetch('/api/risk-parameters');
            const json = await res.json();
            set({ riskParameters: json });
        } catch (e) {
            console.error("Failed to fetch engine parameters", e);
        }
    },

    fetchHistoricalTrades: async () => {
        try {
            const res = await fetch('/api/trades/history');
            const json = await res.json();
            set((state) => {
                // Ensure we don't wipe out a live trade that literally just hit via WebSocket
                const liveIds = new Set(state.activeTrades.map(t => t.id));
                const filteredHistory = json.filter((t: TradeExecution) => !liveIds.has(t.id));
                return { activeTrades: [...state.activeTrades, ...filteredHistory].slice(0, 50) };
            });
        } catch (e) {
            console.error("Failed to fetch historical ledger trades", e);
        }
    }
}));
