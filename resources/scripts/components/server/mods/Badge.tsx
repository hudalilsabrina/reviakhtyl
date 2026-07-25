import styled, { css } from 'styled-components';
import tw from 'twin.macro';

export const Badge = styled.span<{ $variant: 'provider' | 'disabled' | 'installed' | 'manual' }>`
    ${tw`uppercase tracking-wider font-semibold px-1.5 py-0.5 rounded`}
    font-size: 10px;

    ${(props) =>
        props.$variant === 'manual' &&
        css`
            ${tw`bg-blue-600/30 text-blue-200`};
        `}
    ${(props) =>
        props.$variant === 'provider' &&
        css`
            ${tw`bg-gray-700/70 text-gray-300`};
        `}
    ${(props) =>
        props.$variant === 'disabled' &&
        css`
            ${tw`bg-yellow-600/30 text-yellow-200`};
        `}
    ${(props) =>
        props.$variant === 'installed' &&
        css`
            background-color: rgba(34, 197, 94, 0.15);
            color: #4ade80;
            border: 1px solid rgba(74, 222, 128, 0.4);
            display: inline-flex;
            align-items: center;
            gap: 4px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 8px;
        `}
`;
