
At each stage:

- AI generates draft content according to the pipeline.  
- Humans review and edit documents before they are used for tasks.

---

## Documents

Documents are the main objects in Projector.

- They may start as unstructured notes.  
- AI can generate draft versions of other document types based on a pipeline.  
- Humans can edit documents at any stage.  
- Changes are tracked in the system.

---

## Tasks

Tasks are created and assigned by human users.

- Each task is linked to a document.  
- Tasks have an assignee, status, priority, and due date.  
- AI does not assign tasks.  

---

## AI Workflows

Admin users define AI workflows, which specify how AI generates draft documents.

- **System Instructions** — describe the AI’s intended role.  
- **User Prompt Logic** — rules for generating content.  
- **Document Schemas** — expected format for each document type.  
- **Pipelines** — the order in which documents are generated.  

End users do not interact with prompts or pipelines directly.

---

## Extensibility

Project types, document schemas, AI instructions, and pipelines are stored in the database.  
Admins can add or modify workflows without changing application code.

---

## Architecture

- **Backend:** Laravel + Octane, Horizon, Reverb  
- **Frontend:** Vue + Vite  
- **AI Providers:** Ollama (local) or Gemini (cloud)  

---

## Getting Started

### Requirements

- PHP 8.1+  
- Composer  
- Node.js 18+  
- Redis  
- MySQL or PostgreSQL  
- AI provider (Ollama or Gemini)  

### Installation

```bash
git clone https://github.com/agedgouda/projector.git
cd projector

cp .env.example .env

composer install
npm install

php artisan key:generate
php artisan migrate

npm run dev
php artisan serve
