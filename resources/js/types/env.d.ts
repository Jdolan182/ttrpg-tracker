// The app's own Vite variables (from .env). Vite's types already cover import.meta itself.
interface ImportMetaEnv {
    readonly VITE_APP_NAME: string;
}
