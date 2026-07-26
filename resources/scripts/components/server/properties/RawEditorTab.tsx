import { useRef, useState } from 'react';
import tw from 'twin.macro';
import { useTranslation } from 'react-i18next';
import { useStoreState } from 'easy-peasy';
import Card from '@/reviactyl/ui/Card';
import SpinnerOverlay from '@/reviactyl/elements/SpinnerOverlay';
import { Button } from '@/reviactyl/elements/button/index';
import CodemirrorEditor from '@/reviactyl/elements/CodemirrorEditor';
import MonacoEditor from '@/reviactyl/elements/MonacoEditor';
import { ApplicationStore } from '@/state';

interface Props {
    content: string;
    saving: boolean;
    onSave: (content: string) => void;
}

const RawEditorTab = ({ content, saving, onSave }: Props) => {
    const { t } = useTranslation('server/properties');
    const user = useStoreState((state: ApplicationStore) => state.user.data);
    const [mode, setMode] = useState('text/x-properties');
    const fetchContent = useRef<null | (() => Promise<string>)>(null);

    const save = () => {
        if (!fetchContent.current) return;

        fetchContent.current().then(onSave);
    };

    const editorProps = {
        mode,
        filename: 'server.properties',
        initialContent: content,
        onModeChanged: setMode,
        fetchContent: (value: () => Promise<string>) => {
            fetchContent.current = value;
        },
        onContentSaved: save,
    };

    return (
        <div css={tw`space-y-4`}>
            <p css={tw`text-xs text-amber-400/90`}>{t('raw_warning')}</p>
            <Card css={tw`relative !p-1`}>
                <SpinnerOverlay visible={saving} />
                {user?.fileEditor === 'mo' ? <MonacoEditor {...editorProps} /> : <CodemirrorEditor {...editorProps} />}
            </Card>
            <div css={tw`flex justify-end`}>
                <Button disabled={saving} onClick={save}>
                    {saving ? t('saving') : t('raw_save')}
                </Button>
            </div>
        </div>
    );
};

export default RawEditorTab;
