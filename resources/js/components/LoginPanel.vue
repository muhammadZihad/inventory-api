<script setup>
import { reactive, ref } from 'vue';
import { ApiError } from '../api/client.js';
import { useAuth } from '../composables/useAuth.js';
import FieldErrors from './FieldErrors.vue';

const { login, register } = useAuth();

const mode = ref('login');
const busy = ref(false);
const message = ref('');
const fieldErrors = ref({});

const credentials = reactive({ email: 'test@example.com', password: 'password' });
const details = reactive({ name: '', email: '', password: '', password_confirmation: '' });

async function submit() {
    busy.value = true;
    message.value = '';
    fieldErrors.value = {};

    try {
        if (mode.value === 'login') {
            await login({ ...credentials });
        } else {
            await register({ ...details });
        }
    } catch (exception) {
        if (exception instanceof ApiError) {
            message.value = exception.message;
            fieldErrors.value = exception.fieldErrors;
        } else {
            message.value = 'Unable to reach the API.';
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="login">
        <div class="tabs">
            <button type="button" :class="{ active: mode === 'login' }" @click="mode = 'login'">Sign in</button>
            <button type="button" :class="{ active: mode === 'register' }" @click="mode = 'register'">
                Create account
            </button>
        </div>

        <form class="login-form" @submit.prevent="submit">
            <template v-if="mode === 'login'">
                <label>
                    Email
                    <input v-model="credentials.email" type="email" autocomplete="username" required />
                </label>
                <FieldErrors :errors="fieldErrors" field="email" />

                <label>
                    Password
                    <input v-model="credentials.password" type="password" autocomplete="current-password" required />
                </label>
                <FieldErrors :errors="fieldErrors" field="password" />
            </template>

            <template v-else>
                <label>
                    Name
                    <input v-model="details.name" type="text" required />
                </label>
                <FieldErrors :errors="fieldErrors" field="name" />

                <label>
                    Email
                    <input v-model="details.email" type="email" autocomplete="username" required />
                </label>
                <FieldErrors :errors="fieldErrors" field="email" />

                <label>
                    Password
                    <input v-model="details.password" type="password" autocomplete="new-password" required />
                </label>
                <FieldErrors :errors="fieldErrors" field="password" />

                <label>
                    Confirm password
                    <input
                        v-model="details.password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        required
                    />
                </label>
                <FieldErrors :errors="fieldErrors" field="password_confirmation" />
            </template>

            <button type="submit" :disabled="busy">
                {{ busy ? 'Working…' : mode === 'login' ? 'Sign in' : 'Create account' }}
            </button>

            <p v-if="message" class="notice error">{{ message }}</p>
            <p v-if="mode === 'login'" class="eyebrow">Seeded demo account: test@example.com / password</p>
        </form>
    </div>
</template>
