import { describe, expect, it } from 'vitest';
import { connectionState, nextAccountLabel } from '../../resources/js/utils/marketplaceConnection';

const account = (attributes) => ({ id: 1, status: 'active', last_error: null, ...attributes });

describe('connectionState', () => {
    it('shows a healthy account as connected', () => {
        expect(connectionState(account())).toBe('active');
    });

    it('shows a failed check on an active account instead of "connected"', () => {
        expect(connectionState(account({ last_error: 'connect' }))).toBe('unreachable');
        expect(connectionState(account({ last_error: 'other' }))).toBe('check_failed');
    });

    it('keeps the account status for rejected and turned-off accounts', () => {
        expect(connectionState(account({ status: 'invalid_credentials', last_error: 'auth' }))).toBe('invalid_credentials');
        expect(connectionState(account({ status: 'disabled', last_error: 'connect' }))).toBe('disabled');
    });

    it('shows a check in progress first', () => {
        expect(connectionState(account({ status: 'invalid_credentials' }), new Set([1]))).toBe('checking');
    });
});

describe('nextAccountLabel', () => {
    it('starts with the marketplace name', () => {
        expect(nextAccountLabel('Shutterstock', [])).toBe('Shutterstock');
    });

    it('numbers further accounts from 2', () => {
        expect(nextAccountLabel('Shutterstock', ['Shutterstock'])).toBe('Shutterstock 2');
    });

    it('skips names already taken', () => {
        expect(nextAccountLabel('Shutterstock', ['Shutterstock', 'Shutterstock 2', 'Shutterstock 3'])).toBe('Shutterstock 4');
        expect(nextAccountLabel('Shutterstock', ['Shutterstock', 'Shutterstock 3'])).toBe('Shutterstock 2');
    });
});
