import { cn } from '@/lib/utils';
import { createContext, useContext, useState } from 'react';

interface TabsContextValue {
    active: string;
    setActive: (value: string) => void;
}

const TabsContext = createContext<TabsContextValue>({ active: '', setActive: () => {} });

interface TabsProps {
    defaultValue: string;
    value?: string;
    onValueChange?: (value: string) => void;
    className?: string;
    children: React.ReactNode;
}

export function Tabs({ defaultValue, value, onValueChange, className, children }: TabsProps) {
    const [internal, setInternal] = useState(defaultValue);
    const active = value ?? internal;
    const setActive = (v: string) => {
        setInternal(v);
        onValueChange?.(v);
    };
    return (
        <TabsContext.Provider value={{ active, setActive }}>
            <div className={cn('w-full', className)}>{children}</div>
        </TabsContext.Provider>
    );
}

export function TabsList({ className, children }: { className?: string; children: React.ReactNode }) {
    return (
        <div
            className={cn(
                'inline-flex h-9 items-center justify-start rounded-lg bg-muted p-1 text-muted-foreground',
                className,
            )}
        >
            {children}
        </div>
    );
}

interface TabsTriggerProps {
    value: string;
    className?: string;
    children: React.ReactNode;
}

export function TabsTrigger({ value, className, children }: TabsTriggerProps) {
    const { active, setActive } = useContext(TabsContext);
    const isActive = active === value;
    return (
        <button
            type="button"
            onClick={() => setActive(value)}
            className={cn(
                'inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1 text-sm font-medium ring-offset-background transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50',
                isActive ? 'bg-card text-foreground shadow' : 'text-muted-foreground hover:text-foreground',
                className,
            )}
        >
            {children}
        </button>
    );
}

interface TabsContentProps {
    value: string;
    className?: string;
    children: React.ReactNode;
}

export function TabsContent({ value, className, children }: TabsContentProps) {
    const { active } = useContext(TabsContext);
    if (active !== value) return null;
    return (
        <div
            className={cn(
                'mt-2 ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
                className,
            )}
        >
            {children}
        </div>
    );
}
