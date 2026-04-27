import { useEffect } from 'react';
import { useTradingStore } from '../stores/useTradingStore';
import type { OrderBookLevel, TradeExecution } from '../stores/useTradingStore';

export const useEngineEcho = () => {
    const setOrderBook = useTradingStore((state) => state.setOrderBook);
    const updateSentiment = useTradingStore((state) => state.updateSentiment);
    const addTrade = useTradingStore((state) => state.addTrade);
    const addAlphaSignal = useTradingStore((state) => state.addAlphaSignal);
    const setSystemStatus = useTradingStore((state) => state.setSystemStatus);

    useEffect(() => {
        // Assumes resources/js/bootstrap.js initializes window.Echo
        if (typeof window === 'undefined' || !(window as any).Echo) {
            console.error('Laravel Echo infrastructure is missing or failed to initialize.');
            setSystemStatus('DISCONNECTED');
            return;
        }

        const echo = (window as any).Echo;
        setSystemStatus('CONNECTED');

        // Subscribe to our primary metric broadcast layer
        const channel = echo.channel('engine-metrics');

        let lastDepthUpdate = 0;
        channel.listen('.DepthUpdated', (e: { symbol?: string, bids: OrderBookLevel[], asks: OrderBookLevel[] }) => {
            const currentState = useTradingStore.getState();
            const currentDisplaySymbol = currentState.displaySymbol || (currentState.riskParameters.allowed_symbols.length > 0 ? currentState.riskParameters.allowed_symbols[0] : null);

            // Filter out updates for pairs we aren't currently viewing
            if (e.symbol && e.symbol !== currentDisplaySymbol) return;

            if (e.bids && e.asks) {
                const now = Date.now();
                // Throttle the visual React re-renders to 500ms preventing the "blur" effect
                // while the backend PHP daemon retains 100ms precise evaluations.
                if (now - lastDepthUpdate > 500) {
                    setOrderBook(e.bids, e.asks);
                    lastDepthUpdate = now;
                }
            }
        });

        channel.listen('.SentimentScored', (e: { sentiment: number }) => {
            if (e.sentiment !== undefined) {
                updateSentiment(e.sentiment);
            }
        });

        channel.listen('.TradeExecuted', (e: any) => {
            if (e.tradeDetails) {
                addTrade(e.tradeDetails as import('../stores/useTradingStore').TradeExecution);
            }
        });
        
        channel.listen('.SignalRejected', (e: any) => {
            if (e.symbol) {
                // The broadcast payload maps top-level because of how event classes serialize
                addAlphaSignal(e as import('../stores/useTradingStore').AlphaSignal);
            }
        });

        // Safe unmount cleanup protocol
        return () => {
            channel.stopListening('.DepthUpdated');
            channel.stopListening('.SentimentScored');
            channel.stopListening('.TradeExecuted');
            channel.stopListening('.SignalRejected');
            echo.leaveChannel('engine-metrics');
            setSystemStatus('DISCONNECTED');
        };
    }, [setOrderBook, updateSentiment, addTrade, setSystemStatus]);
};
