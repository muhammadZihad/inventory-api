import { computed, reactive } from 'vue';
import { api, getToken, onUnauthorized, setToken } from '../api/client.js';

const state = reactive({
    token: getToken(),
    user: JSON.parse(localStorage.getItem('api_user') || 'null'),
});

// A rejected token means the session is over, whichever request discovered it.
onUnauthorized(() => {
    state.token = '';
    state.user = null;
    setToken('');
    localStorage.removeItem('api_user');
});

/** Session state plus the register / login / logout calls. */
export function useAuth() {
    function persist(payload) {
        state.token = payload.access_token;
        state.user = payload.user;
        setToken(payload.access_token);
        localStorage.setItem('api_user', JSON.stringify(payload.user));
    }

    async function login(credentials) {
        const { data } = await api.post('/auth/login', credentials);
        persist(data);

        return data.user;
    }

    async function register(details) {
        const { data } = await api.post('/auth/register', details);
        persist(data);

        return data.user;
    }

    async function logout() {
        try {
            await api.post('/auth/logout');
        } finally {
            // The local session is cleared even if the revoke call fails, so a
            // user can always sign out of this browser.
            state.token = '';
            state.user = null;
            setToken('');
            localStorage.removeItem('api_user');
        }
    }

    return {
        user: computed(() => state.user),
        isAuthenticated: computed(() => Boolean(state.token)),
        login,
        register,
        logout,
    };
}
