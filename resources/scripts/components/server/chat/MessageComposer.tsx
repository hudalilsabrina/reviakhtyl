import React, { useEffect, useRef } from 'react';
import { FaPaperPlane } from 'react-icons/fa6';
import tw from 'twin.macro';
import { Textarea } from '@/reviactyl/elements/Input';
import { Button } from '@/reviactyl/elements/button/index';

interface Props {
    value: string;
    disabled: boolean;
    placeholder: string;
    onChange: (value: string) => void;
    onSubmit: () => void;
}

const MessageComposer = ({ value, disabled, placeholder, onChange, onSubmit }: Props) => {
    const ref = useRef<HTMLTextAreaElement>(null);

    // Grow with the content instead of scrolling inside a fixed two row box.
    useEffect(() => {
        const element = ref.current;
        if (!element) return;

        element.style.height = 'auto';
        element.style.height = `${Math.min(element.scrollHeight, 200)}px`;
    }, [value]);

    const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key !== 'Enter' || e.shiftKey) return;

        e.preventDefault();
        if (!disabled && value.trim().length > 0) {
            onSubmit();
        }
    };

    return (
        <div css={tw`flex items-end gap-2`}>
            <Textarea
                ref={ref}
                rows={1}
                value={value}
                disabled={disabled}
                placeholder={placeholder}
                onChange={(e) => onChange(e.currentTarget.value)}
                onKeyDown={handleKeyDown}
                css={tw`resize-none`}
            />
            <Button
                type={'button'}
                disabled={disabled || value.trim().length === 0}
                onClick={onSubmit}
                title={'Send message'}
            >
                <FaPaperPlane css={tw`w-4 h-4`} />
            </Button>
        </div>
    );
};

export default MessageComposer;
