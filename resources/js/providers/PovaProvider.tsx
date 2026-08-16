import { createContext, useContext, type ReactNode } from 'react';

type PovaValue = {
    /** Open the panel, optionally sending a question straight into it. */
    open: (question?: string) => void;
};

const PovaContext = createContext<PovaValue | null>(null);

/**
 * The one way in to Pova, from anywhere.
 *
 * The panel in the header and the box on Home used to hold two separate
 * conversations, so a question typed on Home stayed on Home — navigate away and
 * it was gone, and the panel that opened next knew nothing about it. There is
 * one conversation now, and this is how a screen starts it.
 */
export function PovaProvider({ value, children }: { value: PovaValue; children: ReactNode }) {
    return <PovaContext.Provider value={value}>{children}</PovaContext.Provider>;
}

export function usePovaLauncher(): PovaValue {
    const value = useContext(PovaContext);

    if (value === null) {
        throw new Error('usePovaLauncher must be used inside PovaProvider.');
    }

    return value;
}
