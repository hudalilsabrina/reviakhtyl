import tw from 'twin.macro';
import styled from 'styled-components';

const variantClasses = {
    manual: tw`bg-yellow-500/10 text-yellow-300 border-yellow-500/20`,
    provider: tw`bg-blue-500/10 text-blue-300 border-blue-500/20`,
    updated: tw`bg-green-500/10 text-green-300 border-green-500/20`,
    disabled: tw`bg-gray-500/10 text-gray-400 border-gray-500/20`,
};

const Badge = styled.span(({ $variant }: { $variant: keyof typeof variantClasses }) => [
    tw`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium border`,
    variantClasses[$variant] || variantClasses.provider,
]);

export default Badge;
