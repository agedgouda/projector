import { watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import projectTypeRoutes from '@/routes/project-types'

export function useProjectTypeForm(editData: any | null, onSuccess: () => void) {
    const form = useForm({
        name: '',
        icon: 'Briefcase',
        document_schema: [{ label: 'Notes', key: 'intake', is_task: false }] as DocumentSchemaItem[],
        workflow: [] as WorkflowStep[],
    })

    const hydrate = (data: any) => {
        form.name = data.name
        form.icon = data.icon ?? 'Briefcase'
        form.document_schema = (data.document_schema ?? []).map((doc: DocumentSchemaItem) => ({
            ...doc,
            is_task: !!doc.is_task,
        }))
        form.workflow = data.workflow ? [...data.workflow] : []
    }

    watch(() => editData, (val) => (val ? hydrate(val) : form.reset()), { immediate: true })

    // Keep the logic inside the composable
    const addSchemaItem = () => {
        form.document_schema.push({ label: '', key: '', is_task: false })
    }

    const addWorkflowStep = () => {
        form.workflow.push({
            step: form.workflow.length + 1,
            from_key: '',
            to_key: '',
            ai_template_id: null
        })
    }

    const submit = () => {
        const url = editData
            ? projectTypeRoutes.update.url(editData.id)
            : projectTypeRoutes.store.url();

        form[editData ? 'put' : 'post'](url, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                // This runs regardless of success or failure
                console.log('Request finished');
            },
            onSuccess: () => {
                console.log('Success triggered'); // Check your console!
                onSuccess();
            },
            onError: (errors) => {
                console.error('Validation errors:', errors);
            }
        });
};

    const suggestKey = (index: number) => {
        const doc = form.document_schema[index]
        if (doc.key === 'intake' || !doc.label) return
        doc.key = doc.label.toLowerCase().trim().replace(/[^a-z0-9 ]/g, '').replace(/\s+/g, '_')
    }



    return { form, submit, addSchemaItem, addWorkflowStep, suggestKey }
}
