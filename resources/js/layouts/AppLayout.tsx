import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { useLocation, useNavigation } from 'react-router';

import { AccountNotice } from '@/components/shell/AccountNotice';
import { PovaPanel } from '@/components/pova/PovaPanel';
import { usePovaLayout } from '@/hooks/usePovaLayout';
import { PovaProvider } from '@/providers/PovaProvider';
import { NavigationLoadingProvider, useNavigationLoading } from '@/providers/NavigationLoadingProvider';
import { SearchFlyout } from '@/components/shell/SearchFlyout';
import { Sidebar } from '@/components/shell/Sidebar';
import { Toasts } from '@/components/shell/Toasts';
import { Topbar } from '@/components/shell/Topbar';
import { useSession } from '@/providers/SessionProvider';

/**
 * The shell every signed-in screen sits inside.
 *
 * ── Mounted once, for the life of the session ────────────────────────────────
 *
 * This is a layout *route*, so React keeps it mounted across every navigation
 * underneath it. Only the contents of <Outlet /> change. That is what makes
 * moving between screens feel like nothing happened: the sidebar keeps its
 * scroll position and its open sections, the header does not blink, no layout
 * effect re-runs, and the browser has no reason to recalculate the shell's
 * geometry at all.
 *
 * Rendering the shell inside each page — the obvious way — would tear all of it
 * down and rebuild it on every click, which is the single most common reason a
 * single-page application ends up feeling slower than the server-rendered thing
 * it replaced.
 */
export function AppLayout({ children }: { children: ReactNode }) {
    return (
        <NavigationLoadingProvider>
            <AppLayoutInner>{children}</AppLayoutInner>
        </NavigationLoadingProvider>
    );
}

function AppLayoutInner({ children }: { children: ReactNode }) {
    const { tenant } = useSession();
    const { pathname } = useLocation();
    const navigation = useNavigation();
    const [mobileOpen, setMobileOpen] = useState(false);
    const [searchOpen, setSearchOpen] = useState(false);
    const [searchExiting, setSearchExiting] = useState(false);
    const [povaOpen, setPovaOpen] = useState(false);
    const [povaExiting, setPovaExiting] = useState(false);
    const [povaQuestion, setPovaQuestion] = useState<string | null>(null);
    const { layout: povaLayout, setLayout: setPovaLayout } = usePovaLayout();
    const { showLoader, stopNavigation } = useNavigationLoading();
    const searchExitTimer = useRef<number | null>(null);
    const povaExitTimer = useRef<number | null>(null);

    // Track navigation state changes - stop loading when pathname changes
    useEffect(() => {
        // Stop navigation immediately when page changes
        stopNavigation();
    }, [pathname, stopNavigation]);
    
    // Cleanup timers on unmount
    useEffect(() => {
        return () => {
            if (searchExitTimer.current !== null) {
                clearTimeout(searchExitTimer.current);
            }
            if (povaExitTimer.current !== null) {
                clearTimeout(povaExitTimer.current);
            }
        };
    }, []);

    // The one way any screen starts a conversation. Home's box calls this
    // rather than keeping a second thread of its own.
    const povaLauncher = useMemo(
        () => ({
            open: (question?: string) => {
                setSearchOpen(false);
                setPovaQuestion(question ?? null);
                setPovaOpen(true);
            },
        }),
        [],
    );

    // A menu that stays open over the page it just navigated to hides the thing
    // the user asked for.
    useEffect(() => setMobileOpen(false), [pathname]);

    // ⌘K / Ctrl-K opens search from anywhere; a bare slash does too, but only
    // when it would otherwise be a wasted keystroke — inside a field it is a
    // character somebody meant to type.
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;
            const typing =
                target?.tagName === 'INPUT' ||
                target?.tagName === 'TEXTAREA' ||
                target?.isContentEditable;

            if ((event.key === 'k' || event.key === 'K') && (event.metaKey || event.ctrlKey)) {
                event.preventDefault();
                setPovaOpen(false);
                setSearchOpen((was) => !was);
            } else if (event.key === '/' && !typing && !event.metaKey && !event.ctrlKey) {
                event.preventDefault();
                setPovaOpen(false);
                setSearchOpen(true);
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    // Scroll to the top on a real navigation, the way a browser would. Without
    // it, arriving at a long page halfway down is disorienting in a way no
    // amount of speed makes up for.
    useEffect(() => {
        window.scrollTo({ top: 0, behavior: 'instant' });
    }, [pathname]);

    // Two pixels at the top while something is genuinely in flight. Most
    // navigations here resolve on the next frame from prefetched code and
    // cached data, and a bar that flashed on every click would advertise work
    // that is not happening.
    useEffect(() => {
        const bar = document.getElementById('nav-progress')?.firstElementChild as HTMLElement | null;

        if (!bar) {
            return undefined;
        }

        if (navigation.state === 'idle') {
            bar.style.width = '100%';
            bar.style.opacity = '0';

            const reset = window.setTimeout(() => {
                bar.style.width = '0';
                bar.style.opacity = '1';
            }, 250);

            return () => window.clearTimeout(reset);
        }

        bar.style.opacity = '1';
        bar.style.width = '70%';

        return undefined;
    }, [navigation.state]);

    return (
        <PovaProvider value={povaLauncher}>
        <div className="min-h-full">
            {/*
                The bar spans the whole width and the rail begins beneath it,
                rather than the rail running full height with the bar starting
                beside it. The platform's mark then sits in the top-left corner
                of the window — where a product's own name belongs — and the
                rail is free to be only navigation.
            */}
            <Topbar
                mobileOpen={mobileOpen}
                onOpenMobileMenu={() => setMobileOpen(true)}
                onCloseMobileMenu={() => setMobileOpen(false)}
                // Each toggles, and closes the other: two panels anchored to the
                // same corner cannot both be open without covering each other.
                onOpenPalette={() => {
                    // Close Pova with animation if open
                    if (povaOpen) {
                        setPovaExiting(true);
                        povaExitTimer.current = window.setTimeout(() => {
                            setPovaOpen(false);
                            setPovaExiting(false);
                        }, 150);
                    }
                    
                    // Toggle search
                    if (searchOpen) {
                        setSearchExiting(true);
                        searchExitTimer.current = window.setTimeout(() => {
                            setSearchOpen(false);
                            setSearchExiting(false);
                        }, 150);
                    } else {
                        setSearchOpen(true);
                    }
                }}
                onAskAi={() => {
                    // Close search with animation if open
                    if (searchOpen) {
                        setSearchExiting(true);
                        searchExitTimer.current = window.setTimeout(() => {
                            setSearchOpen(false);
                            setSearchExiting(false);
                        }, 150);
                    }
                    
                    // Toggle Pova
                    if (povaOpen) {
                        setPovaExiting(true);
                        povaExitTimer.current = window.setTimeout(() => {
                            setPovaOpen(false);
                            setPovaExiting(false);
                        }, 150);
                    } else {
                        setSearchOpen(false);
                        setPovaOpen(true);
                    }
                }}
            />

            <Sidebar mobileOpen={mobileOpen} onCloseMobile={() => setMobileOpen(false)} />

            {/*
                The content area starts after the topbar
                min-w-0: a flex child defaults to min-width:auto, so wide content
                would stretch this column past the viewport instead of scrolling
                inside its own container.
            */}
            <div className="page-frame flex min-w-0">
                {/*
                    The content column, and beside it — when Pova is docked —
                    the panel. Both are in the flow: the side panel narrows the
                    page rather than covering it, and the full view replaces it
                    outright. Only the floating posture goes over the top, and
                    that one portals itself out of here.
                */}
                {!(povaOpen && povaLayout === 'full') && (
                    <main className="page-surface relative min-w-0 flex-1 px-3 py-3 md:px-0">
                        {/* Navigation loading overlay - only shows if navigation takes longer than 150ms */}
                        {showLoader && (
                            <div 
                                className="fixed inset-0 z-50 flex items-center justify-center bg-white/90 backdrop-blur-sm"
                                style={{ 
                                    marginLeft: 'calc(var(--sidebar-width) + var(--sidebar-padding))',
                                    top: 'var(--topbar-height)',
                                }}
                            >
                                <div className="flex flex-col items-center gap-4">
                                    {/* Logo container with spinning circle */}
                                    <div className="relative flex h-16 w-16 items-center justify-center">
                                        {/* Static border circle */}
                                        <div 
                                            className="absolute inset-0 rounded-full"
                                            style={{ 
                                                border: '3px solid rgba(0, 212, 232, 0.1)'
                                            }}
                                        />
                                        {/* Spinning circle */}
                                        <div 
                                            className="absolute inset-0 animate-spin rounded-full"
                                            style={{
                                                border: '3px solid transparent',
                                                borderTopColor: '#00d4e8',
                                                borderRightColor: '#00d4e8',
                                                animationDuration: '1s'
                                            }}
                                        />
                                        {/* Logo icon in center */}
                                        <img 
                                            src="/img/angisflow-favicon.png" 
                                            alt="Angisflow"
                                            className="relative z-10 h-8 w-8 object-contain"
                                            onError={(e) => {
                                                // Fallback to SVG if logo fails to load
                                                const target = e.target as HTMLImageElement;
                                                target.style.display = 'none';
                                                const parent = target.parentElement;
                                                if (parent) {
                                                    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                                                    svg.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
                                                    svg.setAttribute('viewBox', '0 0 32 32');
                                                    svg.setAttribute('class', 'relative z-10 h-8 w-8');
                                                    
                                                    const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
                                                    rect.setAttribute('width', '32');
                                                    rect.setAttribute('height', '32');
                                                    rect.setAttribute('rx', '8');
                                                    rect.setAttribute('fill', '#0d1b2a');
                                                    
                                                    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                                                    path.setAttribute('d', 'M16 7l7 12H9l7-12z');
                                                    path.setAttribute('fill', '#00d4e8');
                                                    
                                                    const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                                                    circle.setAttribute('cx', '16');
                                                    circle.setAttribute('cy', '23');
                                                    circle.setAttribute('r', '2.2');
                                                    circle.setAttribute('fill', '#00d4e8');
                                                    
                                                    svg.appendChild(rect);
                                                    svg.appendChild(path);
                                                    svg.appendChild(circle);
                                                    
                                                    parent.appendChild(svg);
                                                }
                                            }}
                                        />
                                    </div>
                                </div>
                            </div>
                        )}
                        
                        {tenant?.account.usable === false ? (
                            <AccountStopped />
                        ) : (
                            <>
                                <AccountNotice />
                                {children}
                            </>
                        )}
                    </main>
                )}

                {/* Single PovaPanel instance - always mounted to preserve state across layout changes */}
                {/* For sidebar/full layouts, it renders here in the flex container */}
                {/* For floating layout, it portals itself to document.body (see PovaPanel component) */}
                {povaOpen && (
                    <PovaPanel
                        open
                        exiting={povaExiting}
                        layout={povaLayout}
                        onLayout={setPovaLayout}
                        question={povaQuestion}
                        onQuestionSent={() => setPovaQuestion(null)}
                        onClose={() => {
                            setPovaExiting(true);
                            povaExitTimer.current = window.setTimeout(() => {
                                setPovaOpen(false);
                                setPovaExiting(false);
                            }, 150);
                        }}
                    />
                )}
            </div>

            <SearchFlyout
                open={searchOpen}
                exiting={searchExiting}
                onClose={() => {
                    setSearchExiting(true);
                    searchExitTimer.current = window.setTimeout(() => {
                        setSearchOpen(false);
                        setSearchExiting(false);
                    }, 150);
                }}
                // Finding and asking are the same intent from different ends —
                // handing the query straight over means nobody retypes it.
                onAskPova={() => {
                    setSearchExiting(true);
                    searchExitTimer.current = window.setTimeout(() => {
                        setSearchOpen(false);
                        setSearchExiting(false);
                        setPovaOpen(true);
                    }, 150);
                }}
            />

            <Toasts />
        </div>
        </PovaProvider>
    );
}

/**
 * What a suspended subscriber sees instead of the application.
 *
 * The server refuses these requests anyway — see EnsureAccountIsUsable — so
 * this is not the control, it is the explanation. A screen full of failed
 * requests and no reason is how a billing problem becomes a support call about
 * the software being broken.
 */
function AccountStopped() {
    return (
        <div className="mx-auto max-w-lg py-16 text-center">
            <h1 className="text-2xl font-bold">This account is on hold</h1>
            <p className="mt-3 text-[var(--color-text-body)]">
                Nothing has been deleted and none of your figures have changed. Settling the
                subscription puts everything back exactly as it was.
            </p>
            <a href="/billing" className="btn btn-primary mt-6">
                Open billing
            </a>
        </div>
    );
}
