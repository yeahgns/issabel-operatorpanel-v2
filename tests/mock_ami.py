#!/usr/bin/env python3
"""AMI falso para testar o Snapshot sem Asterisk. Uso: python3 mock_ami.py [porta]"""
import socketserver, sys

def ev(name, **kw):
    d = {'Event': name}
    d.update({k.replace('_', ''): v for k, v in kw.items()})
    return d

def chan(ch, uid, lid, state, app, data='', cid='', cidn='', con='', conn='', ctx='from-internal', exten='s', dur='00:00:10', bridge=''):
    return ev('CoreShowChannel', Channel=ch, Uniqueid=uid, Linkedid=lid, ChannelStateDesc=state, Application=app,
              ApplicationData=data, CallerIDNum=cid, CallerIDName=cidn, ConnectedLineNum=con, ConnectedLineName=conn,
              Context=ctx, Exten=exten, Duration=dur, BridgeId=bridge)

LISTS = {
    'PJSIPShowEndpoints': [
        ev('EndpointList', ObjectName=n, DeviceState=s) for n, s in
        [('201', 'Ringing'), ('205', 'In use'), ('211', 'In use'), ('214', 'In use'), ('203', 'In use'),
         ('204', 'Ringing'), ('206', 'Unavailable'), ('212', 'Not in use'), ('215', 'Not in use'), ('whatsapp', 'Not in use')]
    ],
    'PJSIPShowContacts': [
        ev('ContactList', Endpoint='201', Uri='sip:201@192.168.0.21:5060;ob', Status='Reachable', RoundtripUsec='8211', UserAgent='Yealink SIP-T31P 124.86.0.75'),
        ev('ContactList', Endpoint='205', Uri='sip:205@10.0.0.55:49311', Status='Reachable', RoundtripUsec='31002', UserAgent='MicroSIP/3.21.3'),
        ev('ContactList', Endpoint='whatsapp', Uri='sip:gw@200.100.10.5:5060', Status='Reachable', RoundtripUsec='41000'),
    ],
    'PJSIPShowRegistrationsOutbound': [ev('OutboundRegistrationDetail', ObjectName='whatsapp', Status='Registered')],
    'SIPpeers': [
        ev('PeerEntry', Channeltype='SIP', ObjectName='202', IPaddress='192.168.0.22', Status='OK (12 ms)'),
        ev('PeerEntry', Channeltype='SIP', ObjectName='210', IPaddress='-none-', Status='UNKNOWN'),
        ev('PeerEntry', Channeltype='SIP', ObjectName='operadora', IPaddress='189.1.2.3', Status='OK (23 ms)'),
        ev('PeerEntry', Channeltype='SIP', ObjectName='trunk2', IPaddress='-none-', Status='UNREACHABLE'),
    ],
    'IAXpeerlist': None,
    'ExtensionStateList': [
        ev('ExtensionStatus', Exten=e, Context='ext-local', Hint=h, Status=s) for e, h, s in
        [('201', 'PJSIP/201&Custom:DND201', '8'), ('202', 'SIP/202&Custom:DND202', '8'), ('205', 'PJSIP/205', '1'),
         ('211', 'PJSIP/211', '17'), ('212', 'PJSIP/212&Custom:DND212', '2'), ('206', 'PJSIP/206', '4')]
    ],
    'DeviceStateList': [ev('DeviceStateChange', Device='Custom:DND212', State='BUSY'), ev('DeviceStateChange', Device='Custom:DND201', State='NOT_INUSE')],
    'CoreShowChannels': [
        # 1) entrada pela operadora -> fila 600 tocando 201 e 202
        chan('SIP/operadora-00000010', '1700000000.10', '1700000000.10', 'Up', 'Queue', '600,t,,,300', '19996234875', 'CLIENTE', ctx='ext-queues', exten='600', dur='00:00:25'),
        chan('Local/201@from-queue-00000005;1', '1700000000.11', '1700000000.10', 'Ringing', 'AppQueue', '(Outgoing Line)', ctx='from-queue', exten='600'),
        chan('Local/201@from-queue-00000005;2', '1700000000.12', '1700000000.10', 'Ring', 'Dial', 'PJSIP/201,,trM(auto-blkvm)', ctx='from-queue', exten='201'),
        chan('PJSIP/201-0000002a', '1700000000.13', '1700000000.10', 'Ringing', 'AppDial', '(Outgoing Line)', '201', 'Maria', '19996234875', 'CLIENTE', dur='00:00:08'),
        chan('Local/202@from-queue-00000006;2', '1700000000.14', '1700000000.10', 'Ring', 'Dial', 'SIP/202,,trM(auto-blkvm)', ctx='from-queue', exten='202'),
        chan('SIP/202-00000a01', '1700000000.15', '1700000000.10', 'Ringing', 'AppDial', '(Outgoing Line)', '202', 'Julia', '19996234875', 'CLIENTE', dur='00:00:08'),
        # 2) saída do 205 pela operadora, em conversa
        chan('PJSIP/205-0000002b', '1700000100.20', '1700000100.20', 'Up', 'Dial', 'SIP/operadora/011987654321,300,Tt', '205', 'Samuel', '011987654321', '', ctx='macro-dialout-trunk', dur='00:03:12', bridge='b-1'),
        chan('SIP/operadora-00000011', '1700000100.21', '1700000100.20', 'Up', 'AppDial', '(Outgoing Line)', '1933334444', 'EMPRESA', '205', 'Samuel', dur='00:03:05', bridge='b-1'),
        # 3) interna 211 -> 214
        chan('PJSIP/211-0000002c', '1700000200.30', '1700000200.30', 'Up', 'Dial', 'PJSIP/214,,HhTtr', '211', 'Guilherme', '214', 'Israel', exten='214', dur='00:01:40', bridge='b-2'),
        chan('PJSIP/214-0000002d', '1700000200.31', '1700000200.30', 'Up', 'AppDial', '(Outgoing Line)', '214', 'Israel', '211', 'Guilherme', dur='00:01:38', bridge='b-2'),
        # 4) cliente esperando na fila 602, sem agente livre
        chan('SIP/operadora-00000012', '1700000300.40', '1700000300.40', 'Up', 'Queue', '602,t,,,300', '11955554444', '', ctx='ext-queues', exten='602', dur='00:00:41'),
        # 5) 203 ouvindo a caixa postal
        chan('PJSIP/203-0000002e', '1700000400.50', '1700000400.50', 'Up', 'VoiceMailMain', '203@default', '203', 'Gerlane', ctx='app-vmmain', exten='s', dur='00:00:30'),
        # 6) DID direto para o 204, tocando
        chan('SIP/trunk2-00000013', '1700000500.60', '1700000500.60', 'Ring', 'Dial', 'PJSIP/204,20,tr', '21988887777', 'JOAO', ctx='from-did-direct', exten='204', dur='00:00:27'),
        chan('PJSIP/204-0000002f', '1700000500.61', '1700000500.60', 'Ringing', 'AppDial', '(Outgoing Line)', '204', 'Financeiro', '21988887777', 'JOAO', dur='00:00:27'),
    ],
    'QueueStatus': [
        ev('QueueParams', Queue='600', Strategy='ringall', Calls='1', Completed='12', Abandoned='1'),
        ev('QueueMember', Queue='600', Name='Atendimento', Location='Local/200@from-queue/n', StateInterface='hint:200@ext-local', Membership='static', Status='5', Paused='0', CallsTaken='4'),
        ev('QueueMember', Queue='600', Name='Local/201@from-queue/n', Location='Local/201@from-queue/n', StateInterface='hint:201@ext-local', Membership='static', Status='6', Paused='0', CallsTaken='7'),
        ev('QueueMember', Queue='600', Name='Julia', Location='Local/202@from-queue/n', StateInterface='hint:202@ext-local', Membership='dynamic', Status='6', Paused='0', CallsTaken='3'),
        ev('QueueMember', Queue='600', Name='Eduarda', Location='Local/206@from-queue/n', StateInterface='hint:206@ext-local', Membership='static', Status='5', Paused='0'),
        ev('QueueEntry', Queue='600', Position='1', Channel='SIP/operadora-00000010', CallerIDNum='19996234875', CallerIDName='CLIENTE', Wait='25'),
        ev('QueueParams', Queue='602', Strategy='rrmemory', Calls='1', Completed='5', Abandoned='0'),
        ev('QueueMember', Queue='602', Name='Samuel', Location='PJSIP/205', Membership='static', Status='2', Paused='0', CallsTaken='5'),
        ev('QueueMember', Queue='602', Name='Guilherme', Location='PJSIP/211', Membership='static', Status='2', Paused='0'),
        ev('QueueMember', Queue='602', Name='Israel', Location='PJSIP/214', Membership='static', Status='2', Paused='0'),
        ev('QueueMember', Queue='602', Name='Sarah', Location='PJSIP/215', Membership='dynamic', Status='1', Paused='1', PausedReason='Almoço'),
        ev('QueueEntry', Queue='602', Position='1', Channel='SIP/operadora-00000012', CallerIDNum='11955554444', CallerIDName='', Wait='41'),
        ev('QueueParams', Queue='603', Strategy='ringall', Calls='0', Completed='0', Abandoned='0'),
        ev('QueueMember', Queue='603', Name='Gerlane', Location='PJSIP/203', Membership='static', Status='2', Paused='0'),
        ev('QueueMember', Queue='603', Name='Financeiro', Location='PJSIP/204', Membership='static', Status='6', Paused='0'),
    ],
    'Parkinglots': [ev('Parkinglot', Name='default', StartSpace='701', StopSpace='704', Timeout='45')],
    'ParkedCalls': [ev('ParkedCall', ParkingSpace='702', ParkingLot='default', ParkeeChannel='SIP/operadora-00000009',
                       ParkeeCallerIDNum='11933332222', ParkeeCallerIDName='MERCADO BOM', ParkerDialString='PJSIP/201',
                       ParkingDuration='18', ParkingTimeout='45')],
    'ConfbridgeListRooms': 'error',
    'MeetmeListRooms': None,
    'VoicemailUsersList': [ev('VoicemailUserEntry', VoiceMailbox='201', VMContext='default', NewMessageCount='2', OldMessageCount='5')],
}

class H(socketserver.StreamRequestHandler):
    def send(self, d):
        self.wfile.write((''.join(f'{k}: {v}\r\n' for k, v in d.items()) + '\r\n').encode())

    def handle(self):
        self.wfile.write(b'Asterisk Call Manager/7.0.3\r\n')
        pkt = {}
        for raw in self.rfile:
            line = raw.decode().rstrip('\r\n')
            if line:
                k, _, v = line.partition(':'); pkt[k] = v.strip(); continue
            if not pkt: continue
            a, aid = pkt.get('Action'), pkt.get('ActionID', '')
            pkt = {}
            if a == 'Login':
                self.send({'Response': 'Success', 'ActionID': aid, 'Message': 'Authentication accepted'}); continue
            if a == 'Logoff':
                self.send({'Response': 'Goodbye', 'ActionID': aid}); return
            data = LISTS.get(a)
            if data is None:
                self.send({'Response': 'Error', 'ActionID': aid, 'Message': 'Invalid/unknown command'}); continue
            if data == 'error':
                self.send({'Response': 'Error', 'ActionID': aid, 'Message': 'No active conferences.'}); continue
            self.send({'Response': 'Success', 'ActionID': aid, 'EventList': 'start', 'Message': 'will follow'})
            for e in data:
                self.send({**e, 'ActionID': aid})
            name = data[0]['Event'] if data else a
            self.send({'Event': (a if a != 'QueueStatus' else 'QueueStatus') + 'Complete', 'ActionID': aid, 'EventList': 'Complete', 'ListItems': len(data)})

class S(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True

if __name__ == '__main__':
    S(('127.0.0.1', int(sys.argv[1]) if len(sys.argv) > 1 else 15038), H).serve_forever()
