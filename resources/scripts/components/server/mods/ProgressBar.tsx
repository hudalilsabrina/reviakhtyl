import styled from 'styled-components';

export const ProgressBar = styled.div`
    height: 4px;
    border-radius: 9999px;
    background-color: rgba(255, 255, 255, 0.08);
    overflow: hidden;

    & > div {
        height: 100%;
        border-radius: 9999px;
        background-color: #4ade80;
        transition: width 600ms cubic-bezier(0.4, 0, 0.2, 1);
    }
`;
