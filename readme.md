# Projector — AI-Driven Project Workflows

Projector is an AI-assisted project management system where **notes evolve into living documents**, and documents can generate tasks.

The AI acts like an *unpaid intern* — organizing, drafting, and transforming your work without being in charge.

---

## 🧠 Core Idea

Start with notes.  
Turn them into documents.  
Convert documents into other documents or tasks.  
Repeat as often as needed.

Projector is document-first, not task-first.

---

## ✨ What Makes Projector Different

- Documents are the primary unit of work
- AI assists, but never owns decisions
- Workflows are flexible, not rigid
- Real-time feedback while AI works
- Optional self-hosted or cloud AI models

---

## 🧩 Architecture Highlights

- **Laravel Octane** — high-performance request handling
- **Laravel Reverb** — real-time updates and streaming feedback
- **Laravel Horizon** — background AI jobs and task processing
- **Vue + Vite** — modern frontend
- **Pluggable AI drivers**
  - Ollama (local/self-hosted)
  - Gemini (cloud)
  - More providers planned

---

## 🧪 Current Focus

The initial implementation focuses on **software project workflows**, including:

- Notes → specifications
- Specifications → task lists
- Document revisions and transformations
- Assignable tasks linked to documents

---

## 🚀 Getting Started

### Requirements
- PHP 8.1+
- Composer
- Node 18+
- Redis (for queues & Reverb)
- Database (MySQL/Postgres)
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
