import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FaBrain, FaChevronDown } from 'react-icons/fa6';
import styled from 'styled-components';
import tw from 'twin.macro';

const Toggle = styled.button`
    ${tw`flex items-center gap-1.5 text-2xs text-gray-500 hover:text-gray-300 transition-colors duration-100 px-1 py-0.5 rounded-ui`};

    &:focus-visible {
        ${tw`outline-none ring-1 ring-primary-400`};
    }
`;

const Chevron = styled(FaChevronDown)<{ $open: boolean }>`
    ${tw`w-2.5 h-2.5 transition-transform duration-150`};
    ${({ $open }) => $open && tw`rotate-180`};
`;

const Body = styled.div`
    ${tw`mt-1 rounded-ui border border-gray-700/60 bg-gray-900/40 px-3 py-2`};
    ${tw`text-2xs leading-relaxed text-gray-400 whitespace-pre-wrap break-words`};
    ${tw`max-h-64 overflow-y-auto`};
`;

interface Props {
    reasoning: string;
    /** When the turn is streaming, the reasoning is shown as it arrives. */
    streaming?: boolean;
}

/**
 * Collapsed-by-default view of a reasoning model's chain-of-thought.
 *
 * It is kept out of the answer on purpose: it is often long, frequently
 * contradicts itself before arriving somewhere, and is not something a user
 * should have to read to get their answer. While a turn is streaming it is
 * shown open instead — a live read of what the model is doing beats a spinner.
 */
const ReasoningDisclosure = ({ reasoning, streaming = false }: Props) => {
    const { t } = useTranslation('server/chat');
    const [open, setOpen] = useState(false);

    const visible = open || streaming;

    return (
        <div css={tw`w-full`}>
            <Toggle type={'button'} onClick={() => setOpen((value) => !value)} aria-expanded={visible}>
                <FaBrain css={tw`w-2.5 h-2.5`} />
                <span>{visible ? t('thinking-hide') : t('thinking-show')}</span>
                <Chevron $open={visible} />
            </Toggle>
            {visible && <Body>{reasoning}</Body>}
        </div>
    );
};

export default ReasoningDisclosure;
