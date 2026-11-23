-- IA local da Jack (ADR 0004, etapa A; docs/jack/IA.md): de onde veio cada decisão e quanto a IA demorou.
-- mode = 'shadow' + source = 'ia': o que a IA teria feito (modo sombra), ao lado da decisão real.
ALTER TABLE talk_jack_decisions ADD COLUMN source VARCHAR(10) NOT NULL DEFAULT 'regras' AFTER mode;
ALTER TABLE talk_jack_decisions ADD COLUMN model VARCHAR(60) NULL AFTER source;
ALTER TABLE talk_jack_decisions ADD COLUMN ai_ms INT UNSIGNED NULL AFTER model;
