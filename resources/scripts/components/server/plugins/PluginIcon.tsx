import tw from 'twin.macro';
import { FaPuzzlePiece } from 'react-icons/fa6';

export const PluginIcon = ({ url }: { url: string | null }) =>
    url ? (
        <img src={url} alt={''} css={tw`w-10 h-10 sm:w-12 sm:h-12 rounded-ui object-cover flex-shrink-0`} />
    ) : (
        <div
            css={tw`w-10 h-10 sm:w-12 sm:h-12 rounded-ui bg-gray-800 border border-gray-700 flex items-center justify-center flex-shrink-0`}
        >
            <FaPuzzlePiece css={tw`text-gray-500 text-lg`} />
        </div>
    );
