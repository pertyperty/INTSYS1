import argparse
import os
from math import isfinite
from pathlib import Path

import httpx
import psycopg
from dotenv import load_dotenv


class SmokeFailure(Exception):
    pass


def ollama_smoke(client: httpx.Client, chat_model: str, embedding_model: str, cpu_only: bool) -> tuple[int, int]:
    options = {'num_ctx': 2048}
    if cpu_only:
        options['num_gpu'] = 0

    try:
        chat = client.post('/api/chat', json={
            'model': chat_model,
            'messages': [{'role': 'user', 'content': 'Reply with only the word ready.'}],
            'stream': False,
            'think': False,
            'keep_alive': 0,
            'options': {**options, 'num_predict': 16, 'temperature': 0},
        })
        chat.raise_for_status()
    except httpx.HTTPError:
        raise SmokeFailure('Ollama chat failed. Check reachability and the local model tag.') from None

    try:
        result = chat.json()
        content = result['message']['content']
        if result['done'] is not True or not isinstance(content, str) or not content.strip():
            raise ValueError
    except (KeyError, ValueError, TypeError):
        raise SmokeFailure('Ollama chat did not return a completed text response.') from None

    try:
        embed = client.post('/api/embed', json={
            'model': embedding_model,
            'input': 'SANDIG local smoke test',
            'truncate': False,
            'keep_alive': 0,
            'options': options,
        })
        embed.raise_for_status()
    except httpx.HTTPError:
        raise SmokeFailure('Ollama embedding failed. Check reachability and the local embedding tag.') from None

    try:
        embeddings = embed.json()['embeddings']
        if not isinstance(embeddings, list) or len(embeddings) != 1:
            raise ValueError
        vector = embeddings[0]
        if not isinstance(vector, list) or not vector or not all(
            type(value) in (int, float) and isfinite(value) for value in vector
        ):
            raise ValueError
    except (KeyError, ValueError, TypeError, OverflowError):
        raise SmokeFailure('Ollama embedding did not return a finite vector.') from None

    return len(content.strip()), len(vector)


def postgres_smoke(database_url: str) -> None:
    if not database_url:
        raise SmokeFailure('Set SANDIG_DATABASE_URL with the dedicated backend role first.')
    try:
        with psycopg.connect(database_url, connect_timeout=5, autocommit=True) as connection:
            if connection.execute('SELECT 1').fetchone() != (1,):
                raise SmokeFailure('PostgreSQL did not return the expected probe result.')
    except (psycopg.Error, ValueError):
        raise SmokeFailure('PostgreSQL connection failed. Check the database, role, and local configuration.') from None


def main() -> int:
    parser = argparse.ArgumentParser(description='Check SANDIG local dependencies without processing user input.')
    parser.add_argument('dependency', choices=['ollama', 'postgres'])
    args = parser.parse_args()
    load_dotenv(Path(__file__).resolve().parents[2] / '.env')
    try:
        if args.dependency == 'ollama':
            with httpx.Client(base_url='http://127.0.0.1:11434', timeout=httpx.Timeout(90, connect=3), trust_env=False) as client:
                answer_length, dimensions = ollama_smoke(
                    client,
                    os.getenv('SANDIG_CHAT_MODEL', 'qwen3:4b'),
                    os.getenv('SANDIG_EMBEDDING_MODEL', 'qwen3-embedding:0.6b'),
                    os.getenv('SANDIG_CPU_ONLY', 'false').lower() == 'true',
                )
            print(f'Ollama OK: {answer_length} response characters, {dimensions} embedding dimensions.')
        else:
            postgres_smoke(os.getenv('SANDIG_DATABASE_URL', ''))
            print('PostgreSQL OK: authenticated connection and SELECT 1.')
    except SmokeFailure as error:
        print(error)
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
