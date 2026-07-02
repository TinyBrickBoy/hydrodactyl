import { useVirtualizer } from '@tanstack/react-virtual';
import clsx from 'clsx';
import { type CSSProperties, type ReactNode, useLayoutEffect, useRef, useState } from 'react';

interface VirtualizedListProps<T> {
    items: T[];
    renderItem: (item: T, index: number) => ReactNode;
    estimateSize?: (index: number) => number;
    overscan?: number;
    gap?: number;
    className?: string;
    style?: CSSProperties;
    emptyState?: ReactNode;
    loading?: boolean;
    loadingOverlay?: ReactNode;
    maxHeight?: string | number;
    scrollRef?: React.RefObject<HTMLElement | null>;
    itemClassName?: string;
}

const defaultItemClassName = 'bg-mocha-500 border-mocha-300 border-[1px] rounded-lg transition-all duration-150 p-4';

function VirtualizedList<T>({
    items,
    renderItem,
    estimateSize = () => 80,
    overscan = 5,
    gap = 0,
    className,
    style,
    emptyState,
    loading = false,
    loadingOverlay,
    maxHeight,
    scrollRef,
    itemClassName = defaultItemClassName,
}: VirtualizedListProps<T>) {
    const wrapperRef = useRef<HTMLDivElement>(null);
    const [scrollElement, setScrollElement] = useState<HTMLElement | null>(null);

    useLayoutEffect(() => {
        if (scrollRef?.current) {
            setScrollElement(scrollRef.current);
            return;
        }

        if (maxHeight !== undefined) {
            setScrollElement(wrapperRef.current);
            return;
        }

        const el = wrapperRef.current;
        if (!el) return;

        let parent: HTMLElement | null = el.parentElement;
        while (parent) {
            const overflow = getComputedStyle(parent).overflowY;
            if (overflow === 'auto' || overflow === 'scroll') {
                setScrollElement(parent);
                return;
            }
            parent = parent.parentElement;
        }
    }, [maxHeight, scrollRef]);

    const rowVirtualizer = useVirtualizer({
        count: loading ? 0 : items.length,
        getScrollElement: () => scrollElement,
        estimateSize,
        measureElement: (el) => el.getBoundingClientRect().height,
        overscan,
    });

    if (loading && loadingOverlay) {
        return <div className={clsx(className, 'relative')}>{loadingOverlay}</div>;
    }

    if (!loading && items.length === 0 && emptyState) {
        return <div className={clsx(className)}>{emptyState}</div>;
    }

    const scrollStyle = maxHeight !== undefined ? { maxHeight, overflow: 'auto' as const } : undefined;

    return (
        <div ref={wrapperRef} className={clsx(className)} style={{ ...scrollStyle, ...style }}>
            <div className='relative w-full' style={{ height: `${rowVirtualizer.getTotalSize()}px` }}>
                {rowVirtualizer.getVirtualItems().map((virtualItem) => {
                    const item = items[virtualItem.index];
                    if (!item) return null;
                    return (
                        <div
                            key={virtualItem.key}
                            data-index={virtualItem.index}
                            ref={rowVirtualizer.measureElement}
                            className='absolute left-0 top-0 w-full'
                            style={{
                                transform: `translateY(${virtualItem.start}px)`,
                            }}
                        >
                            {(() => {
                                const wrapped = itemClassName ? (
                                    <div className={itemClassName}>{renderItem(item, virtualItem.index)}</div>
                                ) : (
                                    renderItem(item, virtualItem.index)
                                );
                                return gap > 0 && virtualItem.index < items.length - 1 ? (
                                    <div style={{ paddingBottom: gap }}>{wrapped}</div>
                                ) : (
                                    wrapped
                                );
                            })()}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

export default VirtualizedList;
