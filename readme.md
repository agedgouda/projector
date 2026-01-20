# Projector

Projector is a document-first, AI-assisted workflow engine designed to bridge the gap between unstructured ideas and actionable engineering tasks. 

Unlike generic AI chat tools, Projector uses **admin-defined database pipelines** to ensure AI output follows strict organizational schemas and maintains 1:1 traceability from intake to execution.

---

## Core Philosophy

* **AI Synthesizes:** AI generates structured drafts from raw notes according to strict pipeline logic.
* **Asynchronous Processing:** Long-running AI vectorization and document generation tasks are managed by **Laravel Horizon**, ensuring the UI remains snappy while the heavy lifting happens in the background.
* **Humans Authorize:** Humans review, edit, and approve every document before it is converted into tasks.
* **Database-Driven:** AI prompts, document schemas, and project types live in the database, not the code.

---

## Documents & Traceability

Documents are the source of truth in Projector.

* **Intake:** Start with unstructured meeting notes, transcripts, or brainstorms.
* **Evolution:** AI generates draft versions of other document types (like PRDs or Tech Specs) based on the project's defined pipeline.
* **Traceability:** Changes are tracked, and documents maintain a permanent link to their source, ensuring a clear audit trail from initial idea to final requirement.

---

## Tasks

Tasks represent the execution layer of the platform and are managed entirely by people.

* **Human-Centric:** AI does not assign work. Humans create and assign tasks based on approved documentation.
* **Context-Linked:** Each task is tethered to a specific document, giving assignees immediate access to the requirements and context.
* **Standardized:** Tasks include assignees, status tracking, priority levels, and due dates.

---

## AI Workflows

Admin users define the logic that drives the platform. This allows Projector to adapt to any domain without changing application code.

* **System Instructions:** Describe the AI’s intended role and constraints.
* **User Prompt Logic:** Rules and templates for generating content.
* **Document Schemas:** The expected format and structure for each document type.
* **Pipelines:** The specific order in which documents are generated.

*End users interact with simple buttons; the complexity of prompting and pipeline logic is handled by the admin-defined architecture.*

---

## Architecture



* **Backend:** Laravel + Octane (High-performance server), Horizon (Queue management), Reverb (Real-time WebSockets).
* **Frontend:** Vue + Vite.
* **Cache/Queue:** Redis (Required for Horizon and Reverb synchronization).
* **AI Providers:** **Ollama** (local/private) or **Gemini** (cloud/scale).

---

## Getting Started

### Requirements

* PHP 8.2+
* Composer
* Node.js 18+
* **Redis** (Essential for queue and broadcasting)
* PostgreSQL
* AI provider (Ollama or Gemini)

### Installation

```bash
# Clone the repository
git clone [https://github.com/agedgouda/projector.git](https://github.com/agedgouda/projector.git)
cd projector

# Setup environment
cp .env.example .env

# Install dependencies
composer install
npm install

# Initialize application
php artisan key:generate
php artisan migrate
