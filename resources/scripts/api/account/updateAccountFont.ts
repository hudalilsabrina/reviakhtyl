import http from '@/api/http';

interface Data {
    font: string;
}

export default ({ font }: Data): Promise<void> => {
    return new Promise((resolve, reject) => {
        http.put('/api/client/account/font', { font })
            .then(() => resolve())
            .catch(reject);
    });
};
