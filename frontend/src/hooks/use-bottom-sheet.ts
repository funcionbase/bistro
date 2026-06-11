import { useCallback, useState } from 'react';

export function useBottomSheet(initialOpen = false) {
    const [isOpen, setIsOpen] = useState(initialOpen);

    const open = useCallback(() => setIsOpen(true), []);
    const close = useCallback(() => setIsOpen(false), []);
    const toggle = useCallback(() => setIsOpen((v) => !v), []);

    return { isOpen, open, close, toggle, setIsOpen };
}
