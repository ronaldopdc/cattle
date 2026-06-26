import pandas as pd
import json
import os

file_path = 'Simulação engorda.xls'
if os.path.exists(file_path):
    df = pd.read_excel(file_path)
    print(df.to_json(orient='records', force_ascii=False))
else:
    print(json.dumps({"error": f"File not found: {file_path}"}))
