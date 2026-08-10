import { ref } from 'vue';

declare global {
    interface Window {
        gapi: any;
        google: any;
    }
}

export interface PickedGoogleDoc {
    id: string;
    name: string;
}

// Module-level singleton so the script/library load only ever happens once per page load,
// safely reused across multiple mounts of whatever component calls openPicker().
let loadPromise: Promise<void> | null = null;

function loadPickerApi(): Promise<void> {
    if (loadPromise) {
        return loadPromise;
    }

    loadPromise = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = 'https://apis.google.com/js/api.js';
        script.async = true;
        script.onload = () => {
            window.gapi.load('picker', { callback: resolve, onerror: reject });
        };
        script.onerror = reject;
        document.head.appendChild(script);
    });

    return loadPromise;
}

export function useGooglePicker() {
    const isOpening = ref(false);

    const openPicker = async (opts: {
        accessToken: string;
        apiKey: string;
        appId: string;
        onPicked: (file: PickedGoogleDoc) => void;
    }): Promise<void> => {
        isOpening.value = true;

        try {
            await loadPickerApi();

            const view = new window.google.picker.DocsView(window.google.picker.ViewId.DOCS)
                .setMimeTypes('application/vnd.google-apps.document');

            const picker = new window.google.picker.PickerBuilder()
                .addView(view)
                .setOAuthToken(opts.accessToken)
                .setDeveloperKey(opts.apiKey)
                .setAppId(opts.appId)
                .setCallback((data: any) => {
                    if (data.action === window.google.picker.Action.PICKED) {
                        const doc = data.docs[0];
                        opts.onPicked({ id: doc.id, name: doc.name });
                    }
                })
                .build();

            picker.setVisible(true);
        } finally {
            isOpening.value = false;
        }
    };

    return { isOpening, openPicker };
}
