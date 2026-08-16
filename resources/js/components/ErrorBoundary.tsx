import { Component, type ReactNode } from 'react';

interface Props {
    children: ReactNode;
}

interface State {
    hasError: boolean;
    error: Error | null;
}

export class ErrorBoundary extends Component<Props, State> {
    constructor(props: Props) {
        super(props);
        this.state = { hasError: false, error: null };
    }

    static getDerivedStateFromError(error: Error): State {
        return { hasError: true, error };
    }

    componentDidCatch(error: Error, info: { componentStack: string }) {
        console.error('ErrorBoundary caught:', error, info);
    }

    render() {
        if (this.state.hasError) {
            return (
                <div style={{
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    height: '100vh',
                    backgroundColor: '#1e293b',
                    fontFamily: 'system-ui',
                    color: '#f1f5f9'
                }}>
                    <div style={{ maxWidth: '600px', padding: '2rem', textAlign: 'center' }}>
                        <h1 style={{ fontSize: '2rem', fontWeight: 'bold', marginBottom: '1rem' }}>
                            Something went wrong
                        </h1>
                        <pre style={{
                            backgroundColor: '#0f172a',
                            padding: '1rem',
                            borderRadius: '0.5rem',
                            overflowX: 'auto',
                            textAlign: 'left',
                            fontSize: '0.875rem'
                        }}>
                            {this.state.error?.toString()}
                            {'\n\n'}
                            {this.state.error?.stack}
                        </pre>
                        <button
                            onClick={() => window.location.reload()}
                            style={{
                                marginTop: '2rem',
                                padding: '0.75rem 1.5rem',
                                backgroundColor: '#3b82f6',
                                color: 'white',
                                border: 'none',
                                borderRadius: '0.375rem',
                                cursor: 'pointer',
                                fontSize: '1rem'
                            }}
                        >
                            Reload Page
                        </button>
                    </div>
                </div>
            );
        }

        return this.props.children;
    }
}
