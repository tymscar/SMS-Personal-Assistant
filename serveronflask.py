# Use this website http://127.0.0.1:5000/?text=cuvant&sourcelang=ro&destlang=en




from flask import Flask
from urlparse import urlparse
from flask import Flask, render_template, request
import os,sys,subprocess
import requests

# Initialize the Flask application
app = Flask(__name__)

# This is a catch all route, to catch any request the user does
@app.route('/')
def index():

  qsText = request.args.get('text', None)
  qsl = request.args.get('sourcelang', None)
  qdl = request.args.get('destlang', None)
  filecsv = open("country-codes.csv","r")
  filecontent = filecsv.readlines()
  for line in filecontent:
  	line = line.split(",")
  	if line[0].lower() == qsl.lower() or line[1].lower() == qsl.lower():
  		qsl = line[2]
  	if line[0].lower() == qdl.lower() or line[1].lower() == qdl.lower():
  		qdl = line[2]
  #qs = request.query_string
  qsl=qsl.strip("\r\n\t ")
  qdl=qdl.strip("\r\n\t ")
  var = subprocess.check_output("gawk -f translate -shell -b %s:%s \"%s\" "  %(qsl,qdl,qsText), shell=True)
  parms = {
    'api_key': '',
    'api_secret': '',
    'to': '',
    'from': 'Oscar\'s Robot',
    'text': var 
  }
  #r = requests.get('https://rest.nexmo.com/sms/json?', params=parms)
  return var

if __name__ == '__main__':
    app.run(
        host="0.0.0.0",
        port=int("80"),
    )
