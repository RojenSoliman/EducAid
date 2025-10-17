# Free Chatbot Access Options (No Billing Required)

This note captures quick alternatives while the Gemini API quota is exhausted. All options below can be used without attaching a billing account, though each comes with daily limits or feature constraints.

## 1. Hosted Web Chatbots (no API work needed)

| Service | What you get | Typical daily limits | Notes |
|---------|--------------|----------------------|-------|
| **OpenAI ChatGPT** (https://chat.openai.com/) | ChatGPT web UI (GPT-4o mini / GPT-3.5) | Soft daily cap based on demand | No API key on the free tier; sign in with email/Google/Apple. |
| **Google Gemini** (https://gemini.google.com/) | Gemini Advanced-style chat UI | Usage throttled per account | Free web access only; API still requires billing. |
| **Perplexity** (https://www.perplexity.ai/) | Conversational search with citations | Generous free tier, falls back to smaller models after limit | Great for research-style responses; login recommended to lift anonymous caps. |
| **DuckDuckGo AI Chat** (https://duckduckgo.com/aichat) | Claude 3 Haiku, Llama 3, or Mixtral via private relay | 1–2 dozen prompts/day | Email required to increase quota; keeps prompts anonymous. |
| **Poe** (https://poe.com/) | Unified interface for GPT, Claude, Llama, etc. | Daily message limits per model | Mobile + web apps; free tier sufficient for light testing. |

## 2. Free API or Self-Hosted Options

| Option | Setup effort | Cost profile | Why use it |
|--------|--------------|--------------|------------|
| **Ollama + local models** (https://ollama.com/) | Install Ollama binary on desktop server, pull a GGUF model (e.g., `llama2`, `mistral`, `phi3`) | Free, uses local CPU/GPU resources | Fast prototyping without network dependence; call via HTTP on `localhost`. |
| **LM Studio** (https://lmstudio.ai/) | Desktop app that downloads + serves open models | Free; optional GPU acceleration | Point-and-click interface for running / chatting with models; exposes local API. |
| **Text-generation-webui** (https://github.com/oobabooga/text-generation-webui) | Docker or Python install; run GGML/GGUF or GPTQ models | Free, hardware dependent | Extensive UI (chat + fine-tuning tools) and REST API adapter. |
| **Hugging Face Inference Endpoints (Community)** | Minimal (sign in, use hosted “Spaces”) | Free for community-run spaces; performance varies | Good for experimenting with hosted demos; not guaranteed uptime. |

## 3. Picking the Right Stopgap

1. **Need quick answers with zero setup?** Use the web UIs (ChatGPT, Gemini, Perplexity, DuckDuckGo, Poe).
2. **Need temporary chatbot inside EducAid while Gemini billing is sorted?** Consider embedding an iframe to one of the hosted chat UIs or guiding users to the service directly.
3. **Need an API-like surface without billing?** Stand up a local model with Ollama or LM Studio and point the PHP chatbot to `http://localhost:11434/v1/chat/completions` (Ollama’s OpenAI-compatible route). This keeps all traffic on the app server and avoids remote quotas.
4. **Need steady production service?** Resume Gemini or switch to a billed OpenAI/Anthropic account—the free tiers above are unsuitable for sustained public traffic.

## 4. Next Steps Checklist

- [ ] Decide whether to wait for Gemini quota reset or migrate temporarily.
- [ ] If going local, prototype with Ollama (`ollama run mistral`) and wire a small PHP wrapper.
- [ ] If relying on a hosted free UI, update user-facing messaging to direct them there.
- [ ] Track when Gemini billing is re-enabled so the primary chatbot can reconnect.

Keep this document alongside the other feature guides so future on-call responders immediately know the fallback options when an API limit is hit.
