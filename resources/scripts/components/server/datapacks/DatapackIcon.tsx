import tw from 'twin.macro';
import styled from 'styled-components';

const DatapackIconWrapper = styled.div`
    ${tw`w-10 h-10 sm:w-12 sm:h-12 rounded-ui bg-gray-800 flex items-center justify-center flex-shrink-0`}
`;

interface Props {
    url?: string | null;
}

export const DatapackIcon = ({ url }: Props) => (
    <DatapackIconWrapper>
        {url ? (
            <img src={url} alt="" css={tw`w-6 h-6 sm:w-7 sm:h-7 rounded object-cover`} />
        ) : (
            <svg viewBox="0 0 24 24" css={tw`w-6 h-6 sm:w-7 sm:h-7 text-gray-500`} fill="none" stroke="currentColor" strokeWidth="1.8">
                <path strokeLinecap="round" strokeLinejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.3-4.216.46-6.378.46a48.84 48.84 0 0 1-11.96-.46C2.288 20.62 1.5 19.678 1.5 18.585v-4.93a2.25 2.25 0 0 1 .66-1.59l7.5-7.5a2.25 2.25 0 0 1 3.18 0l7.5 7.5a2.25 2.25 0 0 1 .66 1.59v4.93Z" />
            </svg>
        )}
    </DatapackIconWrapper>
);
