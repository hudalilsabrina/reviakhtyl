import { ServerContext } from '@/state/server';
import TitledGreyBox from '@/reviactyl/elements/TitledGreyBox';
import { Field as FormikField, Form, Formik, FormikHelpers, useFormikContext } from 'formik';
import { Actions, useStoreActions } from 'easy-peasy';
import renameServer from '@/api/server/renameServer';
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

interface Values {
    name: string;
    description: string;
    icon: string;
}

const RenameServerBox = () => {
    const { t } = useTranslation('server/settings');
    const { isSubmitting, values } = useFormikContext<Values>();
    const server = ServerContext.useStoreState((state) => state.server.data!);

    return (
        <TitledGreyBox title={t('rename.title')} css={tw`relative`}>
            <SpinnerOverlay visible={isSubmitting} />
            <Form css={tw`mb-0`}>
                <div className={'flex gap-4'}>
                    <div className={'flex-1'}>
                        <Field id={'name'} name={'name'} label={t('rename.name')} type={'text'} />
                    </div>
                    <div className={'flex items-center'}>
                        {values.icon ? (
                            <img
                                src={values.icon}
                                onError={(e) => ((e.target as HTMLImageElement).style.display = 'none')}
                                className={'h-14 w-14 object-cover rounded-ui border border-gray-700'}
                            />
                        ) : (
                            <img
                                src={server.eggImage || '/reviactyl/icon.png'}
                                className={'h-14 w-14 object-cover rounded-ui border border-gray-700'}
                            />
                        )}
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
