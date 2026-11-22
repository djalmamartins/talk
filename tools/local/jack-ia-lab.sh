#!/usr/bin/env bash
# IA local da Jack no laboratório do Mac (docs/jack/IA.md): Ollama no próprio Mac, com o Gemma 3 1B.
# 1) confere o Ollama e baixa o modelo; 2) mede a IA nas frases fixas (as 40 de conferência são as que valem); 3) se você quiser, liga a IA
#    em modo de teste (JACK_AI=shadow) no .env do Talk: ela só registra o que faria, quem decide continuam
#    sendo as regras. Não apaga nada; só acrescenta linhas ao .env, e pergunta antes.
# Uso: tools/local/jack-ia-lab.sh            (MODEL=gemma3:1b ou MODEL=qwen3:1.7b tools/local/jack-ia-lab.sh para comparar)
set -euo pipefail

project=$(cd "$(dirname "$0")/../.." && pwd)
envf="$project/.env"
model=${MODEL:-jack-ia-1b}   # jack-ia-1b = Gemma 3 1B com nome e ajustes da Jack (tools/local/jack-ia/Modelfile)
envget() { { grep -E "^$1=" "$envf" 2>/dev/null || true; } | tail -1 | cut -d= -f2-; }
confirm() {
  [[ "${YES:-}" == "1" ]] && return 0
  local a; read -r -p "$1 [s/N] " a
  [[ "$a" =~ ^[sS]$ ]]
}

echo "1/4 Ollama"
if ! command -v ollama >/dev/null; then
  echo "O Ollama não está instalado. Instale com:  brew install ollama"
  echo "(ou baixe o aplicativo em ollama.com) e rode este script de novo."
  exit 1
fi
if ! curl -fsS http://localhost:11434/api/tags >/dev/null 2>&1; then
  echo "Iniciando o Ollama em segundo plano…"
  (brew services start ollama >/dev/null 2>&1 || nohup ollama serve >/tmp/ollama.log 2>&1 &)
  for _ in $(seq 1 20); do curl -fsS http://localhost:11434/api/tags >/dev/null 2>&1 && break; sleep 1; done
fi
curl -fsS http://localhost:11434/api/tags >/dev/null || { echo "O Ollama não respondeu em localhost:11434."; exit 1; }
echo "  Ollama no ar."

echo "2/4 Modelo $model"
if [[ "$model" == "jack-ia-1b" ]]; then
  ollama pull gemma3:1b
  ollama create jack-ia-1b -f "$project/tools/local/jack-ia/Modelfile"
else
  ollama pull "$model"
fi

echo "3/4 Placar nas frases fixas (regras x IA)"
# PHP do Mac, se tiver; senão o PHP do container do lab (que alcança o Mac por host.docker.internal)
if command -v php >/dev/null; then
  JACK_AI_URL=http://localhost:11434 JACK_AI_MODEL="$model" php "$project/bin/jack-cenarios.php" --sem-banco --ia
else
  docker exec -e JACK_AI_URL=http://host.docker.internal:11434 -e JACK_AI_MODEL="$model" dev-apache \
    php /var/www/html/talk/bin/jack-cenarios.php --sem-banco --ia
fi

echo "4/4 Banco e modo de teste no Talk do lab"
# a tela Serviço da Jack e o modo de teste precisam das migrações 024 e 025
if docker ps --format '{{.Names}}' 2>/dev/null | grep -qx dev-apache; then
  docker exec dev-apache php /var/www/html/talk/bin/migrate.php --lab | tail -2
else
  echo "  O lab (dev-apache) não está rodando; quando subir, rode:"
  echo "  docker exec dev-apache php /var/www/html/talk/bin/migrate.php --lab"
fi
if [[ -n "$(envget JACK_AI)" ]]; then
  echo "  O .env já tem JACK_AI=$(envget JACK_AI) (mantido). Modelo no .env: $(envget JACK_AI_MODEL)"
  if [[ "$(envget JACK_AI_MODEL)" != "$model" ]]; then echo "  Para a tela usar este modelo, troque no .env: JACK_AI_MODEL=$model"; fi
elif confirm "Ligar a IA em modo de teste (só registra, não decide) no .env do Talk?"; then
  {
    echo
    echo "# IA local da Jack (tools/local/jack-ia-lab.sh): shadow = só registra o que faria"
    echo "JACK_AI=shadow"
    echo "JACK_AI_URL=http://host.docker.internal:11434"
    echo "JACK_AI_MODEL=$model"
    echo "JACK_AI_TIMEOUT=10"
  } >>"$envf"
  echo "  Pronto. Mande uma mensagem de teste (ex.: docker exec dev-apache php /var/www/html/talk/bin/simular.php \"o elevador parou\")"
  echo "  e veja \"IA em teste\" na tela Serviço da Jack."
else
  echo "  Nada alterado no .env."
fi
