-- Arquivos (imagem, áudio, vídeo, PDF, documento): nome original para mostrar e enviar.
-- media_path: caminho relativo no storage, ou "pending:<ref>" / "failed:<ref>" enquanto baixa do WhatsApp.
ALTER TABLE talk_messages ADD COLUMN media_name VARCHAR(255) NULL AFTER media_mime;
ALTER TABLE talk_messages ADD COLUMN media_size INT UNSIGNED NULL AFTER media_name;
