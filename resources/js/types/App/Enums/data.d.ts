export type BuildLocationRule =
    | 0
    | 1
    | 2
    | 3
    | 4
    | 5
    | 6
    | 7
    | 8
    | 9
    | 10
    | 11
    | 12
    | 13
    | 14
    | 15
    | 16
    | 17
    | 18
    | 19;
export type PermittedRotations = 0 | 1 | 2 | 3 | 4;
export type SubstanceTexture =
    | 'None'
    | 'Magma'
    | 'MoltenMetal'
    | 'Polluted'
    | 'Oil'
    | 'Thick'
    | 'Sap'
    | 'CrystalFragments'
    | 'Ovolene'
    | 'Mucus'
    | 'Ink'
    | 'PollutedBrine';
export type UplinkMessageType =
    | 'client-session.start'
    | 'client-zone.list'
    | 'client-zone.layout'
    | 'client-zone.slow'
    | 'client-zone.fast'
    | 'client-colony.state'
    | 'client-command.ack';
