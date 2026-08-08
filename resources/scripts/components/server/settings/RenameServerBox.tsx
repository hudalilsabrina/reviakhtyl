import React, { useRef, useState } from 'react';
import { ServerContext } from '@/state/server';
import TitledGreyBox from '@/reviactyl/elements/TitledGreyBox';
import { Field as FormikField, Form, Formik, FormikHelpers, useFormikContext } from 'formik';
import { Actions, useStoreActions } from 'easy-peasy';
import renameServer from '@/api/server/renameServer';
import uploadServerIcon from '@/api/server/uploadServerIcon';
import Field from '@/reviactyl/elements/Field';
import { object, string } from 'yup';
import SpinnerOverlay from '@/reviactyl/elements/SpinnerOverlay';
import { ApplicationStore } from '@/state';
import { httpErrorToHuman } from '@/api/http';
import { Button } from '@/reviactyl/components/button/index';
import tw from 'twin.macro';
import Label from '@/reviactyl/elements/Label';
import FormikFieldWrapper from '@/reviactyl/elements/FormikFieldWrapper';
import { Textarea } from '@/reviactyl/elements/Input';
import { useTranslation } from 'react-i18next';
import { FaUpload } from 'react-icons/fa6';

interface Values {
    name: string;
    description: string;
    icon: string;
}

const RenameServerBox = () => {
    const { t } = useTranslation('server/settings');
    const { isSubmitting, values, setFieldValue } = useFormikContext<Values>();
    const server = ServerContext.useStoreState((state) => state.server.data!);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);
    const [uploadError, setUploadError] = useState<string | null>(null);

    const preview = values.icon || server.eggImage || '/reviactyl/icon.png';

    const onFileSelected = (event: React.ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];
        if (!file) return;

        setUploading(true);
        setUploadError(null);
        uploadServerIcon(server.uuid, file)
            .then((icon) => setFieldValue('icon', icon))
            .catch((error) => setUploadError(httpErrorToHuman(error)))
            .finally(() => {
                setUploading(false);
                if (fileInputRef.current) {
                    fileInputRef.current.value = '';
                }
            });
    };

    return (
        <TitledGreyBox title={t('rename.title')} css={tw`relative`}>
            <SpinnerOverlay visible={isSubmitting} />
            <Form css={tw`mb-0`}>
                <div className={'flex gap-4'}>
                    <div className={'flex-1'}>
                        <Field id={'name'} name={'name'} label={t('rename.name')} type={'text'} />
                    </div>
                    <div className={'flex flex-col items-center justify-center gap-2'}>
                        <img
                            src={preview}
                            onError={(e) => ((e.target as HTMLImageElement).src = '/reviactyl/icon.png')}
                            className={'h-14 w-14 object-cover rounded-ui border border-gray-700'}
                        />
                        <div className={'flex items-center gap-2'}>
                            <input
                                ref={fileInputRef}
                                type={'file'}
                                accept={'image/png,image/jpeg,image/gif,image/webp'}
                                className={'hidden'}
                                onChange={onFileSelected}
                            />
                            <button
                                type={'button'}
                                onClick={() => fileInputRef.current?.click()}
                                disabled={uploading}
                                className={
                                    'flex items-center gap-1 text-xs px-2 py-1 rounded-ui bg-gray-800 border border-gray-700 text-gray-200 transition hover:brightness-110 disabled:opacity-50'
                                }
                            >
                                <FaUpload />
                                {uploading ? t('rename.icon-uploading') : t('rename.icon-upload')}
                            </button>
                            {values.icon && (
                                <button
                                    type={'button'}
                                    onClick={() => setFieldValue('icon', '')}
                                    className={
                                        'text-xs px-2 py-1 rounded-ui bg-gray-800 border border-gray-700 text-gray-400 transition hover:brightness-110'
                                    }
                                >
                                    {t('rename.icon-remove')}
                                </button>
                            )}
                        </div>
                        {uploadError && <p className={'text-xs text-red-200'}>{uploadError}</p>}
                    </div>
                </div>
                <div css={tw`mt-6`}>
                    <Label>{t('rename.description')}</Label>
                    <FormikFieldWrapper name={'description'}>
                        <FormikField as={Textarea} name={'description'} rows={3} />
                    </FormikFieldWrapper>
                </div>
                <div css={tw`mt-6`}>
                    <Field
                        id={'icon'}
                        name={'icon'}
                        label={t('rename.icon')}
                        type={'url'}
                        description={t('rename.icon-description')}
                    />
                </div>
                <div css={tw`mt-6 text-right`}>
                    <Button type={'submit'}>{t('rename.button')}</Button>
                </div>
            </Form>
        </TitledGreyBox>
    );
};

export default () => {
    const server = ServerContext.useStoreState((state) => state.server.data!);
    const setServer = ServerContext.useStoreActions((actions) => actions.server.setServer);
    const { addError, clearFlashes } = useStoreActions((actions: Actions<ApplicationStore>) => actions.flashes);

    const submit = ({ name, description, icon }: Values, { setSubmitting }: FormikHelpers<Values>) => {
        clearFlashes('settings');
        renameServer(server.uuid, name, description, icon || null)
            .then(() => setServer({ ...server, name, description, icon: icon || null }))
            .catch((error) => {
                console.error(error);
                addError({ key: 'settings', message: httpErrorToHuman(error) });
            })
            .then(() => setSubmitting(false));
    };

    return (
        <Formik
            onSubmit={submit}
            initialValues={{
                name: server.name,
                description: server.description,
                icon: server.icon || '',
            }}
            validationSchema={object().shape({
                name: string().required().min(1),
                description: string().nullable(),
                icon: string().nullable().url(),
            })}
        >
            <RenameServerBox />
        </Formik>
    );
};
