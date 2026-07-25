import { useEffect, useState } from 'react';

// Timed fake-ish progress: eases toward 90%, jumps to 100% on finish.
export const useProgress = (active: boolean) => {
    const [width, setWidth] = useState(0);

    useEffect(() => {
        if (!active) {
            setWidth(0);
            return;
        }
        setWidth(10);
        const timer = setInterval(() => setWidth((w) => (w >= 90 ? w : w + (90 - w) * 0.08 + 1)), 400);

        return () => clearInterval(timer);
    }, [active]);

    return width;
};
