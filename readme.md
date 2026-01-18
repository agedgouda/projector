
At each stage:
- AI generates a draft document
- Humans review and edit the result
- Humans decide what becomes actionable work

---

## 📄 Documents

Documents are the primary unit of work in Projector.

Documents can:
- start as unstructured notes
- be transformed into other document types via AI
- be edited manually at any stage
- retain their context throughout the pipeline

Documents evolve as understanding improves.

---

## 🧵 Tasks (Human-Assigned Only)

Tasks are optional and always created and assigned by a human.

Each task:
- is linked to a document
- has an assignee
- includes priority, status, and due date

AI never assigns tasks.

Tasks exist to execute decisions that humans have reviewed and approved.

---

## 🤖 AI Workflows (Admin Only)

AI workflows are defined and managed by admin users.

Workflows consist of:
- **System Instructions**  
  Define the role, expertise, and constraints of the AI.
- **User Prompt Logic**  
  Structured transformation rules with parameterized input.
- **Document Schemas**  
  Enforce predictable, machine-usable output.
- **Pipelines**  
  Define the order in which documents are transformed.

End users never interact directly with prompts or pipelines.

---

## 🧬 Extensible by Design

Projector’s workflows are **data-driven**, not hardcoded.

The following are stored in the database:
- Project types
- Document schemas
- AI instructions
- Transformation pipelines

This allows new workflows and domains to be added without changing application code, while maintaining consistent execution and safety.

---

## ⚙️ Architecture Overview

- **Laravel Octane** — high-performance runtime
- **Laravel Reverb** — real-time updates and progress feedback
- **Laravel Horizon** — background job processing for AI tasks
- **Vue + Vite** — frontend application
- **Pluggable AI providers**
  - Ollama (local / self-hosted)
  - Gemini (cloud)

---

## 🚀 Getting Started

### Requirements

- PHP 8.1+
- Composer
- Node.js 18+
- Redis
- MySQL or PostgreSQL
- AI provider (Ollama or Gemini)

---

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
