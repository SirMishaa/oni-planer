"""Put the repo root on sys.path so `import scripts.kanim...` resolves."""
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
